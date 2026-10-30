<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Common\Entities\Issues\ErrorFrames\IssueErrorFrame;
use DDD\Domain\Common\Entities\Issues\ErrorFrames\IssueErrorFrames;
use DDD\Infrastructure\Exceptions\Exception as DDDException;
use Doctrine\DBAL\Exception\DriverException;
use Error;
use ErrorException;
use PDOException;
use Throwable;

/**
 * The two stable hashes of a throwable — no line numbers and no free-text message in either, so a deploy that moves code
 * and a message that carries ids keep the same groups:
 *
 * - `error.group_hash` (root cause, what alerts): exception class + throw site (relative file + function, also when it
 *   is a vendor or DDD frame) + the first application frame + a discriminator — the SQLSTATE of a database error, the
 *   code of a DDD exception, the normalised message of a generic engine error (`Error`, `ErrorException`) only;
 * - `error.issue_hash` (one bug reached through one entry point): the root cause + the first application frames + the
 *   entry point (route / command / transport). One bug through two routes = one group, two issues.
 *
 * A frame is (relative file, function); "application" = its file is not under `vendor/`. Closure names lose their line
 * (`{closure:App\X::y():12}` → `{closure:App\X::y()}`).
 */
class IssuesFingerprintBuilder
{
    /** Number of application frames the issue hash takes */
    public const int ISSUE_APPLICATION_FRAMES = 4;

    /** In-app frames of {@see self::getErrorFrames()} that carry their source lines */
    public const int CODE_CONTEXT_FRAMES = 2;

    /** Source lines before and after the frame's line */
    public const int CODE_CONTEXT_LINES_AROUND = 3;

    /** {@see self::getDisplayFile()}: a file outside the project shows relative to an ancestor at most this far up */
    public const int DISPLAY_MAX_PARENT_LEVELS = 2;

    /** A source line of the code context is cut here */
    public const int CODE_CONTEXT_MAX_LINE_LENGTH = 240;

    /** @var array<string, string> Cache: file path as given → resolved path */
    protected array $resolvedPaths = [];

    /**
     * @param string $projectDirectory The application root; '' = the directory that holds this package's `vendor/`
     *                                 (the container may not be there, e.g. for a fatal error at shutdown)
     */
    public function __construct(protected string $projectDirectory = '')
    {
        if ($projectDirectory === '' && str_contains(__FILE__, '/vendor/')) {
            $projectDirectory = (string)strstr(__FILE__, '/vendor/', true);
        }
        // the trace's paths are resolved; a project directory reached through a symlink would never match them
        $this->projectDirectory = rtrim($projectDirectory !== '' ? (realpath($projectDirectory) ?: $projectDirectory) : '', '/');
    }

    public function buildGroupHash(Throwable $throwable): string
    {
        $frames = $this->getFrames($throwable);
        $throwSite = $frames[0] ?? '';
        $firstApplicationFrame = $this->getApplicationFrames($frames, 1)[0] ?? '';
        return $this->hash(implode('|', [
            get_class($throwable),
            $throwSite,
            $firstApplicationFrame,
            $this->getDiscriminator($throwable),
        ]));
    }

    public function buildIssueHash(Throwable $throwable, string $groupHash, string $entryPoint): string
    {
        $applicationFrames = $this->getApplicationFrames($this->getFrames($throwable), self::ISSUE_APPLICATION_FRAMES);
        return $this->hash(implode('|', [$groupHash, implode(';', $applicationFrames), $this->normaliseFunctionName($entryPoint)]));
    }

    /**
     * The frames of the throwable, innermost first, as `relative/file.php#Class::function` — the function that was
     * executing and the file its code is in (the throw site is the first).
     *
     * @return string[]
     */
    public function getFrames(Throwable $throwable): array
    {
        $frames = [];
        $codeFile = $throwable->getFile();
        foreach ($throwable->getTrace() as $traceFrame) {
            $function = ($traceFrame['class'] ?? '') !== ''
                ? $traceFrame['class'] . '::' . $traceFrame['function']
                : (string)($traceFrame['function'] ?? '');
            $frames[] = $this->getRelativeFile($codeFile) . '#' . $this->normaliseFunctionName($function);
            // the next frame's code is in the file this frame was called from
            $codeFile = (string)($traceFrame['file'] ?? '');
        }
        if ($frames === []) {
            $frames[] = $this->getRelativeFile($codeFile) . '#{main}';
        }
        return $frames;
    }

    /**
     * The frames of the throwable for DISPLAY (`error.frames`), innermost first — never a hash input, so
     * {@see self::getFrames()} and the hashes stay exactly as they are. Frame 0 is the throw site
     * (`getFile()` / `getLine()`), frame i the call site of trace[i-1], the last one the entry script (`{main}`). Paths
     * are relative to the project root. The first $codeContextFrameCount in-app frames carry the source lines around
     * their line ($codeContextLinesAround before and after), read now — never function arguments.
     */
    public function getErrorFrames(
        Throwable $throwable,
        int $codeContextFrameCount = self::CODE_CONTEXT_FRAMES,
        int $codeContextLinesAround = self::CODE_CONTEXT_LINES_AROUND
    ): IssueErrorFrames {
        $issueErrorFrames = new IssueErrorFrames();
        $codeFile = $throwable->getFile();
        $codeLine = $throwable->getLine();
        $trace = $throwable->getTrace();
        foreach ($trace as $traceFrame) {
            $function = ($traceFrame['class'] ?? '') !== ''
                ? $traceFrame['class'] . ($traceFrame['type'] ?? '::') . $traceFrame['function']
                : (string)($traceFrame['function'] ?? '');
            $this->addErrorFrame($issueErrorFrames, $codeFile, $codeLine, $function);
            // the next frame's code is in the file this frame was called from, at the call's line
            $codeFile = (string)($traceFrame['file'] ?? '');
            $codeLine = isset($traceFrame['line']) ? (int)$traceFrame['line'] : null;
        }
        if ($codeFile !== '' || $trace === []) {
            $this->addErrorFrame($issueErrorFrames, $codeFile, $codeLine, '{main}');
        }
        $framesWithCodeContext = 0;
        foreach ($issueErrorFrames->getElements() as $issueErrorFrame) {
            if ($framesWithCodeContext >= $codeContextFrameCount) {
                break;
            }
            if ($issueErrorFrame->inApp && $issueErrorFrame->line !== null && $this->addCodeContext($issueErrorFrame, $codeContextLinesAround)) {
                $framesWithCodeContext++;
            }
        }
        return $issueErrorFrames;
    }

    protected function addErrorFrame(IssueErrorFrames $issueErrorFrames, string $file, ?int $line, string $function): void
    {
        $issueErrorFrame = new IssueErrorFrame();
        $issueErrorFrame->file = $this->getDisplayFile($file);
        $issueErrorFrame->line = $line;
        $issueErrorFrame->function = $this->normaliseFunctionName($function);
        $issueErrorFrame->inApp = $issueErrorFrame->file !== '' && !str_starts_with($issueErrorFrame->file, 'vendor/')
            && !str_contains($issueErrorFrame->file, '/vendor/');
        $issueErrorFrames->add($issueErrorFrame);
    }

    /**
     * The source lines around the frame's line, from the file under the project root; false when the file is not
     * readable there (an eval, a phar, a file outside the project).
     */
    protected function addCodeContext(IssueErrorFrame $issueErrorFrame, int $linesAround): bool
    {
        $absoluteFile = str_starts_with($issueErrorFrame->file, '/')
            ? $issueErrorFrame->file
            : $this->projectDirectory . '/' . $issueErrorFrame->file;
        if ($this->projectDirectory === '' || !str_starts_with($absoluteFile, $this->projectDirectory . '/') || !is_file($absoluteFile)) {
            return false;
        }
        $sourceLines = @file($absoluteFile, FILE_IGNORE_NEW_LINES);
        if ($sourceLines === false) {
            return false;
        }
        $firstIndex = max(0, (int)$issueErrorFrame->line - 1 - $linesAround);
        $issueErrorFrame->codeContextStartLine = $firstIndex + 1;
        $issueErrorFrame->codeContextLines = array_map(
            static fn(string $sourceLine): string => mb_substr($sourceLine, 0, self::CODE_CONTEXT_MAX_LINE_LENGTH),
            array_slice($sourceLines, $firstIndex, 2 * $linesAround + 1)
        );
        return true;
    }

    /**
     * @param string[] $frames
     * @return string[] The first $count frames whose file is application code (not under vendor/)
     */
    public function getApplicationFrames(array $frames, int $count): array
    {
        $applicationFrames = [];
        foreach ($frames as $frame) {
            $file = strstr($frame, '#', true);
            if ($file === '' || $file === false || str_starts_with($file, 'vendor/') || str_contains($file, '/vendor/')) {
                continue;
            }
            $applicationFrames[] = $frame;
            if (count($applicationFrames) >= $count) {
                break;
            }
        }
        return $applicationFrames;
    }

    /**
     * What tells two errors of the same class at the same place apart without their message: the SQLSTATE of a
     * database error, the code of a DDD exception, and only for generic engine errors the normalised message.
     */
    public function getDiscriminator(Throwable $throwable): string
    {
        if ($throwable instanceof IssuesGroupDiscriminatorInterface) {
            return 'own:' . $throwable->getIssuesGroupDiscriminator();
        }
        if ($throwable instanceof DriverException && $throwable->getSQLState() !== null) {
            return 'sqlstate:' . $throwable->getSQLState();
        }
        if ($throwable instanceof PDOException) {
            return 'sqlstate:' . ($throwable->errorInfo[0] ?? (string)$throwable->getCode());
        }
        if ($throwable instanceof DDDException) {
            return 'code:' . $throwable->getCode();
        }
        if (in_array(get_class($throwable), [Error::class, ErrorException::class], true)) {
            return 'message:' . $this->normaliseMessage($throwable->getMessage());
        }
        return '';
    }

    /** Numbers, UUIDs, hex ids, quoted literals and timestamps become placeholders */
    public function normaliseMessage(string $message): string
    {
        $patterns = [
            '/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?\b/' => '{timestamp}',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
            '/"[^"]*"|\'[^\']*\'/' => '{literal}',
            '/\b0x[0-9a-f]+\b|\b[0-9a-f]{12,}\b/i' => '{hex}',
            '/\d+(\.\d+)?/' => '{number}',
        ];
        return (string)preg_replace(array_keys($patterns), array_values($patterns), $message);
    }

    public function normaliseFunctionName(string $function): string
    {
        // PHP 8.4 closure names carry the line: {closure:App\X::y():12}
        return (string)preg_replace('/(\{closure:[^}]*?):\d+\}/', '$1}', $function);
    }

    public function getRelativeFile(string $file): string
    {
        if ($file === '') {
            return '';
        }
        if ($this->projectDirectory !== '' && str_starts_with($file, $this->projectDirectory . '/')) {
            return substr($file, strlen($this->projectDirectory) + 1);
        }
        // the entry script as invoked (`php bin/console` through a symlinked path, a relative path): resolve it
        $resolvedFile = $this->resolvedPaths[$file] ??= (realpath($file) ?: $file);
        if ($this->projectDirectory !== '' && str_starts_with($resolvedFile, $this->projectDirectory . '/')) {
            return substr($resolvedFile, strlen($this->projectDirectory) + 1);
        }
        $file = $resolvedFile;
        $vendorPosition = strpos($file, '/vendor/');
        return $vendorPosition !== false ? substr($file, $vendorPosition + 1) : $file;
    }

    /**
     * A file for DISPLAY: relative to the project root like {@see self::getRelativeFile()} (which feeds the hashes and
     * is never changed), and a file outside it — an entry script one level above the project (`public/index.php` next
     * to `backend/`) — relative to the nearest common directory at most {@see self::DISPLAY_MAX_PARENT_LEVELS} levels up
     * (`../public/index.php`) instead of absolute.
     */
    public function getDisplayFile(string $file): string
    {
        $relativeFile = $this->getRelativeFile($file);
        if (!str_starts_with($relativeFile, '/') || $this->projectDirectory === '') {
            return $relativeFile;
        }
        $parentDirectory = $this->projectDirectory;
        for ($parentLevel = 1; $parentLevel <= self::DISPLAY_MAX_PARENT_LEVELS; $parentLevel++) {
            $parentDirectory = dirname($parentDirectory);
            if ($parentDirectory === '/' || $parentDirectory === '.') {
                break;
            }
            if (str_starts_with($relativeFile, $parentDirectory . '/')) {
                return str_repeat('../', $parentLevel) . substr($relativeFile, strlen($parentDirectory) + 1);
            }
        }
        return $relativeFile;
    }

    protected function hash(string $value): string
    {
        return hash(in_array('xxh128', hash_algos(), true) ? 'xxh128' : 'md5', $value);
    }
}
