<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\ErrorFrames;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One frame of a backend error for display (`error.frames` of an issues line): where the code ran, innermost first.
 * Never a hash input — the group and issue hashes are built from {@see \DDD\Infrastructure\Logging\IssuesFingerprintBuilder::getFrames()}.
 */
class IssueErrorFrame extends ValueObject
{
    /** @var string The file relative to the project root (`src/Domain/…/X.php`, `vendor/…`), absolute outside it */
    public string $file = '';

    /** @var int|null The line: the throw site for the first frame, the call site for every other */
    public ?int $line = null;

    /** @var string The function that was executing, `Class::method` or `{main}` */
    public string $function = '';

    /** @var bool Whether the file is the application's own code (not under `vendor/`) */
    public bool $inApp = false;

    /** @var int|null The line number of the first of {@see self::$codeContextLines} */
    public ?int $codeContextStartLine = null;

    /** @var string[] The source lines around {@see self::$line} (first in-app frames only), as read at log time */
    public array $codeContextLines = [];

    public function uniqueKey(): string
    {
        // the same frame may repeat (recursion): order matters, duplicates stay
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
