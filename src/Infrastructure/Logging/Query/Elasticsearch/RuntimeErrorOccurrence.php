<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Issues\ErrorFrames\IssueErrorFrame;
use DDD\Domain\Common\Entities\Issues\ErrorFrames\IssueErrorFrames;

/**
 * One occurrence of a group (a `recent_occurrences` hit of the detail). Message, stack trace, route and every value a
 * user or a payload can influence are redacted. Besides the fields every line has, it carries what an issues-log line
 * of mgamadeus/ddd ≥ 3.12.6 adds — frames with code context, causes, route parameters, the worker and console fields;
 * an older line leaves them null or empty.
 */
class RuntimeErrorOccurrence extends ValueObject
{
    /** The occurrence timestamp, ISO-8601 as ES returns it (`_source["@timestamp"]`). */
    public ?string $timestamp = null;

    /** The occurrence's error message, redacted (`_source["message"]`). */
    public ?string $message = null;

    /** The occurrence's stack trace, redacted (`_source["error.stack_trace"]`). */
    public ?string $stackTrace = null;

    /** The occurrence's route name, redacted (`_source["route_name"]`); null for CLI/Messenger errors. */
    public ?string $route = null;

    /** The exception class, e.g. `BadRequestException` (`_source["error.type"]`). */
    public ?string $errorType = null;

    /** The event's id, unique per line (`event.id`) */
    public ?string $eventId = null;

    /** The log level, e.g. `CRITICAL` (`log.level`) */
    public ?string $level = null;

    /** Where the error happened: `HTTP`, `CLI`, `MESSENGER`, `WEB`, … (`section`) */
    public ?string $section = null;

    /** The root cause hash (`error.group_hash`) */
    public ?string $groupHash = null;

    /** The root cause through this entry point (`error.issue_hash`) */
    public ?string $issueHash = null;

    /** The entry point: route, `CLI:command` or `MessageHandler:transport` (`error.entry_point`) */
    public ?string $entryPoint = null;

    /** `Error` or `Exception` (`error.kind`) */
    public ?string $errorKind = null;

    /** The exception code as a string (`error.code`) */
    public ?string $errorCode = null;

    /** False when nothing caught the error (`error.handled`) */
    public ?bool $handled = null;

    /** The basename of the throw site's file (`log.origin.file.name`) */
    public ?string $throwSiteFileName = null;

    /** The throw site's line (`log.origin.file.line`) */
    public ?int $throwSiteLine = null;

    /** The application environment, e.g. `prod` (`service.environment`, else `environment`) */
    public ?string $environment = null;

    /** The dev workspace or stage (`service.workspace`, else the configured workspace field) */
    public ?string $workspace = null;

    /** The release that ran (`service.version`) */
    public ?string $release = null;

    /** The host or pod (`host.hostname`) */
    public ?string $hostName = null;

    /** The account of the request or job (`account.id`) */
    public ?int $accountId = null;

    /** The location (the configured location field, default `lastLocationId`) */
    public ?int $locationId = null;

    /** The HTTP method of the request (`http.request.method`) */
    public ?string $httpMethod = null;

    /** The response status of an uncaught HTTP error (`http_status`) */
    public ?int $httpStatus = null;

    /** The matched route's parameters, values redacted (`route_parameters`) */
    public RuntimeErrorKeyValues $routeParameters;

    /** The console command of a CLI error (`command`) */
    public ?string $command = null;

    /** The console command's exit code (`exit_code`) */
    public ?int $exitCode = null;

    /** The worker fields of a failed Messenger job; null for other errors */
    public ?RuntimeErrorOccurrenceMessenger $messenger = null;

    /** The frames, innermost (the throw site) first, with the code context of the first in-app frames (`error.frames`) */
    public IssueErrorFrames $errorFrames;

    /** The cause chain, outermost first (`error.causes`) */
    public RuntimeErrorCauses $causes;

    public function __construct()
    {
        $this->routeParameters = new RuntimeErrorKeyValues();
        $this->errorFrames = new IssueErrorFrames();
        $this->causes = new RuntimeErrorCauses();
        parent::__construct();
    }

    /**
     * @param array<string, mixed> $hit One decoded hit
     */
    public static function buildFromHit(
        array $hit,
        RuntimeErrorFieldRedactor $fieldRedactor,
        ?ElasticsearchFieldNames $fieldNames = null
    ): static {
        $fieldNames ??= new ElasticsearchFieldNames();
        $runtimeErrorOccurrence = new static();
        $source = $hit['_source'] ?? [];
        $runtimeErrorOccurrence->timestamp = static::readString($source, '@timestamp');
        $runtimeErrorOccurrence->message = static::readRedactedString($source, 'message', $fieldRedactor);
        $runtimeErrorOccurrence->stackTrace = static::readRedactedString($source, 'error.stack_trace', $fieldRedactor);
        $runtimeErrorOccurrence->route = static::readRedactedString($source, 'route_name', $fieldRedactor);
        $runtimeErrorOccurrence->errorType = static::readString($source, 'error.type');

        $runtimeErrorOccurrence->eventId = static::readString($source, 'event.id');
        $runtimeErrorOccurrence->level = static::readString($source, 'log.level');
        $runtimeErrorOccurrence->section = static::readString($source, 'section');
        $runtimeErrorOccurrence->groupHash = static::readString($source, 'error.group_hash');
        $runtimeErrorOccurrence->issueHash = static::readString($source, 'error.issue_hash');
        $runtimeErrorOccurrence->entryPoint = static::readRedactedString($source, 'error.entry_point', $fieldRedactor);
        $runtimeErrorOccurrence->errorKind = static::readString($source, 'error.kind');
        $runtimeErrorOccurrence->errorCode = static::readString($source, 'error.code');
        $runtimeErrorOccurrence->handled = static::readBool($source, 'error.handled');
        $runtimeErrorOccurrence->throwSiteFileName = static::readString($source, 'log.origin.file.name');
        $runtimeErrorOccurrence->throwSiteLine = static::readInt($source, 'log.origin.file.line');
        $runtimeErrorOccurrence->environment = static::readString($source, 'service.environment') ?? static::readString($source, 'environment');
        $runtimeErrorOccurrence->workspace = static::readString($source, 'service.workspace')
            ?? static::readString($source, $fieldNames->workspaceField);
        $runtimeErrorOccurrence->release = static::readString($source, 'service.version');
        $runtimeErrorOccurrence->hostName = static::readString($source, 'host.hostname');
        $runtimeErrorOccurrence->accountId = static::readInt($source, 'account.id');
        $runtimeErrorOccurrence->locationId = static::readInt($source, $fieldNames->locationField);
        $runtimeErrorOccurrence->httpMethod = static::readString($source, 'http.request.method');
        $runtimeErrorOccurrence->httpStatus = static::readInt($source, 'http_status');
        $routeParameters = static::readSourceField($source, 'route_parameters');
        if (is_array($routeParameters)) {
            $runtimeErrorOccurrence->routeParameters = RuntimeErrorKeyValues::buildFromLoggedMap($routeParameters, $fieldRedactor);
        }
        $runtimeErrorOccurrence->command = static::readString($source, 'command');
        $runtimeErrorOccurrence->exitCode = static::readInt($source, 'exit_code');
        $runtimeErrorOccurrence->messenger = static::buildMessengerFromSource($source, $fieldRedactor);
        $errorFrames = static::readSourceField($source, 'error.frames');
        if (is_array($errorFrames)) {
            $runtimeErrorOccurrence->errorFrames = static::buildErrorFramesFromSource($errorFrames, $fieldRedactor);
        }
        $causes = static::readSourceField($source, 'error.causes');
        if (is_array($causes)) {
            $runtimeErrorOccurrence->causes = static::buildCausesFromSource($causes, $fieldRedactor);
        }
        return $runtimeErrorOccurrence;
    }

    /**
     * A field of the hit's `_source`: the flat dotted key the ECS formatter writes (`error.frames`), else the nested
     * path (`{"error": {"frames": …}}`); null when absent.
     *
     * @param array<string, mixed> $source
     */
    public static function readSourceField(array $source, string $dottedField): mixed
    {
        if (array_key_exists($dottedField, $source)) {
            return $source[$dottedField];
        }
        $fieldParts = explode('.', $dottedField);
        // a nested object whose own keys may still carry dots: {"messenger": {"origin.route": …}}
        for ($splitIndex = 1; $splitIndex < count($fieldParts); $splitIndex++) {
            $head = implode('.', array_slice($fieldParts, 0, $splitIndex));
            if (isset($source[$head]) && is_array($source[$head])) {
                $value = static::readSourceField($source[$head], implode('.', array_slice($fieldParts, $splitIndex)));
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }

    /** @param array<string, mixed> $source */
    protected static function readString(array $source, string $dottedField): ?string
    {
        $value = static::readSourceField($source, $dottedField);
        return is_scalar($value) ? (string)$value : null;
    }

    /** @param array<string, mixed> $source */
    protected static function readRedactedString(array $source, string $dottedField, RuntimeErrorFieldRedactor $fieldRedactor): ?string
    {
        $value = static::readString($source, $dottedField);
        return $value !== null ? $fieldRedactor->redact($value) : null;
    }

    /** @param array<string, mixed> $source */
    protected static function readInt(array $source, string $dottedField): ?int
    {
        $value = static::readSourceField($source, $dottedField);
        return is_numeric($value) ? (int)$value : null;
    }

    /** @param array<string, mixed> $source */
    protected static function readBool(array $source, string $dottedField): ?bool
    {
        $value = static::readSourceField($source, $dottedField);
        if (is_bool($value)) {
            return $value;
        }
        return is_string($value) && in_array(strtolower($value), ['true', 'false'], true) ? strtolower($value) === 'true' : null;
    }

    /**
     * The inverse of the write side's `error.frames` context: `file`, `line`, `function`, `in_app`,
     * `code_context.start_line` / `.lines` (each line redacted).
     *
     * @param array<int, mixed> $framesSource
     */
    protected static function buildErrorFramesFromSource(array $framesSource, RuntimeErrorFieldRedactor $fieldRedactor): IssueErrorFrames
    {
        $issueErrorFrames = new IssueErrorFrames();
        foreach ($framesSource as $frameSource) {
            if (!is_array($frameSource)) {
                continue;
            }
            $issueErrorFrame = new IssueErrorFrame();
            $issueErrorFrame->file = (string)($frameSource['file'] ?? '');
            $issueErrorFrame->line = isset($frameSource['line']) && is_numeric($frameSource['line']) ? (int)$frameSource['line'] : null;
            $issueErrorFrame->function = (string)($frameSource['function'] ?? '');
            $issueErrorFrame->inApp = (bool)($frameSource['in_app'] ?? false);
            $codeContext = $frameSource['code_context'] ?? null;
            if (is_array($codeContext) && is_array($codeContext['lines'] ?? null)) {
                $issueErrorFrame->codeContextStartLine = isset($codeContext['start_line']) ? (int)$codeContext['start_line'] : null;
                $issueErrorFrame->codeContextLines = array_map(
                    static fn(mixed $sourceLine): string => $fieldRedactor->redact((string)$sourceLine),
                    array_values($codeContext['lines'])
                );
            }
            $issueErrorFrames->add($issueErrorFrame);
        }
        return $issueErrorFrames;
    }

    /** @param array<int, mixed> $causesSource */
    protected static function buildCausesFromSource(array $causesSource, RuntimeErrorFieldRedactor $fieldRedactor): RuntimeErrorCauses
    {
        $runtimeErrorCauses = new RuntimeErrorCauses();
        foreach ($causesSource as $causeSource) {
            if (!is_array($causeSource)) {
                continue;
            }
            $runtimeErrorCause = new RuntimeErrorCause();
            $runtimeErrorCause->errorType = isset($causeSource['type']) ? (string)$causeSource['type'] : null;
            $runtimeErrorCause->message = isset($causeSource['message']) ? $fieldRedactor->redact((string)$causeSource['message']) : null;
            $runtimeErrorCause->throwSite = isset($causeSource['file']) ? (string)$causeSource['file'] : null;
            if (is_array($causeSource['frames'] ?? null)) {
                $runtimeErrorCause->errorFrames = static::buildErrorFramesFromSource($causeSource['frames'], $fieldRedactor);
            }
            $runtimeErrorCauses->add($runtimeErrorCause);
        }
        return $runtimeErrorCauses;
    }

    /**
     * The worker fields; null when the hit has no `messenger.*` field.
     *
     * @param array<string, mixed> $source
     */
    protected static function buildMessengerFromSource(array $source, RuntimeErrorFieldRedactor $fieldRedactor): ?RuntimeErrorOccurrenceMessenger
    {
        $hasMessengerField = is_array($source['messenger'] ?? null);
        foreach (array_keys($source) as $sourceKey) {
            $hasMessengerField = $hasMessengerField || str_starts_with((string)$sourceKey, 'messenger.');
        }
        if (!$hasMessengerField) {
            return null;
        }
        $runtimeErrorOccurrenceMessenger = new RuntimeErrorOccurrenceMessenger();
        $runtimeErrorOccurrenceMessenger->messageClass = static::readString($source, 'messenger.message_class');
        $runtimeErrorOccurrenceMessenger->handler = static::readString($source, 'messenger.handler');
        $messageKeys = static::readSourceField($source, 'messenger.message_keys');
        if (is_array($messageKeys)) {
            $runtimeErrorOccurrenceMessenger->messageKeys = RuntimeErrorKeyValues::buildFromLoggedMap($messageKeys, $fieldRedactor);
        }
        $runtimeErrorOccurrenceMessenger->attempt = static::readInt($source, 'messenger.attempt');
        $runtimeErrorOccurrenceMessenger->retryCount = static::readInt($source, 'messenger.retry_count');
        $runtimeErrorOccurrenceMessenger->final = static::readBool($source, 'messenger.final');
        $runtimeErrorOccurrenceMessenger->willRetry = static::readBool($source, 'will_retry');
        $transports = static::readSourceField($source, 'transports');
        $runtimeErrorOccurrenceMessenger->transports = is_array($transports)
            ? array_values(array_map('strval', array_filter($transports, 'is_scalar')))
            : [];
        $runtimeErrorOccurrenceMessenger->originRoute = static::readRedactedString($source, 'messenger.origin.route', $fieldRedactor);
        $runtimeErrorOccurrenceMessenger->originCommand = static::readString($source, 'messenger.origin.command');
        $runtimeErrorOccurrenceMessenger->originAccountId = static::readInt($source, 'messenger.origin.account_id');
        $runtimeErrorOccurrenceMessenger->originWorkspace = static::readString($source, 'messenger.origin.workspace');
        $runtimeErrorOccurrenceMessenger->originDispatchedAt = static::readString($source, 'messenger.origin.dispatched_at');
        $runtimeErrorOccurrenceMessenger->ageSeconds = static::readInt($source, 'messenger.age_seconds');
        return $runtimeErrorOccurrenceMessenger;
    }

    public function uniqueKey(): string
    {
        // two occurrences may share a timestamp: both must survive add()
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
