<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrame;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrames;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One logged error event as the backend returns it: the fields the registry, the CLI and the admin screen use, plus the
 * whole line as JSON for the full context.
 */
class IssueEvent extends ValueObject
{
    public function __construct(
        public readonly ?DateTime $timestamp = null,
        public readonly ?string $level = null,
        public readonly ?string $message = null,
        public readonly ?string $eventId = null,
        public readonly ?string $groupHash = null,
        public readonly ?string $issueHash = null,
        public readonly ?string $entryPoint = null,
        public readonly ?string $exceptionType = null,
        public readonly ?string $slug = null,
        public readonly ?string $logOrigin = null,
        public readonly ?string $section = null,
        public readonly ?string $environment = null,
        public readonly ?string $release = null,
        public readonly ?string $routeName = null,
        public readonly ?string $accountId = null,
        /** @var string|null `service.workspace` (older lines: `workspace`) */
        public readonly ?string $workspace = null,
        /** @var string The whole event as JSON */
        public readonly string $rawJson = '',
    ) {
        parent::__construct();
    }

    /** Builds the event from one ECS JSON line (dotted keys, as the issues log writes them) */
    public static function fromJsonLine(string $jsonLine): ?static
    {
        $fields = json_decode($jsonLine, true);
        if (!is_array($fields)) {
            return null;
        }
        $field = static function (string $key) use ($fields): ?string {
            $value = $fields[$key] ?? null;
            if ($value === null && str_contains($key, '.')) {
                // nested ECS shape: {"log": {"level": …}}
                $value = $fields;
                foreach (explode('.', $key) as $keyPart) {
                    $value = is_array($value) ? ($value[$keyPart] ?? null) : null;
                }
            }
            return is_scalar($value) ? (string)$value : null;
        };
        $timestamp = $field('@timestamp');
        $file = $field('log.origin.file.name');
        $line = $field('log.origin.file.line');
        return new static(
            timestamp: $timestamp !== null ? DateTime::fromString($timestamp) : null,
            level: $field('log.level'),
            message: $field('message'),
            eventId: $field('event.id'),
            groupHash: $field('error.group_hash'),
            issueHash: $field('error.issue_hash'),
            entryPoint: $field('error.entry_point'),
            exceptionType: $field('error.type'),
            slug: $field('error.fingerprint_short'),
            logOrigin: $file !== null ? $file . ($line !== null ? ':' . $line : '') : null,
            section: $field('section'),
            environment: $field('service.environment') ?? $field('environment'),
            release: $field('service.version'),
            routeName: $field('route_name'),
            accountId: $field('account.id'),
            workspace: $field('service.workspace') ?? $field('workspace'),
            rawJson: $jsonLine,
        );
    }

    /**
     * The structured frames of a client error (`client.frames`, innermost first); empty for a backend error. The input
     * of {@see \DDD\Infrastructure\Services\SourceMapResolverService::resolveFrames()} with {@see self::$release}.
     */
    public function getClientFrames(): ClientIssueFrames
    {
        $clientIssueFrames = new ClientIssueFrames();
        $fields = json_decode($this->rawJson, true);
        if (!is_array($fields)) {
            return $clientIssueFrames;
        }
        // flat (`client.frames`) or nested ECS shape (`{"client": {"frames": …}}`)
        $frames = $fields['client.frames'] ?? $fields['client']['frames'] ?? null;
        if (!is_array($frames)) {
            return $clientIssueFrames;
        }
        foreach ($frames as $frameFields) {
            if (!is_array($frameFields) || !isset($frameFields['file'])) {
                continue;
            }
            $clientIssueFrame = new ClientIssueFrame();
            $clientIssueFrame->file = (string)$frameFields['file'];
            $clientIssueFrame->line = isset($frameFields['line']) ? (int)$frameFields['line'] : null;
            $clientIssueFrame->column = isset($frameFields['column']) ? (int)$frameFields['column'] : null;
            $clientIssueFrame->function = isset($frameFields['function']) ? (string)$frameFields['function'] : null;
            $clientIssueFrame->inApp = (bool)($frameFields['in_app'] ?? false);
            $clientIssueFrames->add($clientIssueFrame);
        }
        return $clientIssueFrames;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->eventId ?? (string)spl_object_id($this));
    }
}
