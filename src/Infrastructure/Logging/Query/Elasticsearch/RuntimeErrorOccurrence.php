<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One occurrence of a group (a `recent_occurrences` hit), message, stack trace and route redacted.
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

    /**
     * @param array<string, mixed> $hit One decoded hit
     */
    public static function buildFromHit(array $hit, RuntimeErrorFieldRedactor $fieldRedactor): static
    {
        $runtimeErrorOccurrence = new static();
        $source = $hit['_source'] ?? [];
        $runtimeErrorOccurrence->timestamp = isset($source['@timestamp']) ? (string)$source['@timestamp'] : null;
        $runtimeErrorOccurrence->message = isset($source['message']) ? $fieldRedactor->redact((string)$source['message']) : null;
        $runtimeErrorOccurrence->stackTrace = isset($source['error.stack_trace'])
            ? $fieldRedactor->redact((string)$source['error.stack_trace'])
            : null;
        $runtimeErrorOccurrence->route = isset($source['route_name']) ? $fieldRedactor->redact((string)$source['route_name']) : null;
        $runtimeErrorOccurrence->errorType = isset($source['error.type']) ? (string)$source['error.type'] : null;
        return $runtimeErrorOccurrence;
    }

    public function uniqueKey(): string
    {
        // two occurrences may share a timestamp: both must survive add()
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
