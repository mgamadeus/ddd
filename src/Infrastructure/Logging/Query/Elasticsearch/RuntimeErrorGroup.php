<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One runtime-error group of the GROUPS (or DETAIL) aggregation: count, affected locations, first seen and the newest
 * occurrence's fields (message, stack trace and route redacted). COARSE buckets have a composite key: the display
 * fingerprint is `<type>|<file>` and there is no canonical fingerprint (the hash would be one arbitrary occurrence's).
 */
class RuntimeErrorGroup extends ValueObject
{
    public int $count = 0;

    public ?string $errorType = null;

    public ?string $latestMessage = null;

    public ?string $latestTimestamp = null;

    public ?string $route = null;

    public ?string $stackTrace = null;

    public int $affectedLocationCount = 0;

    /** The group key: `error.fingerprint_short` (FINGERPRINT) or `<type>|<file>` (COARSE) */
    public ?string $displayFingerprint = null;

    /** `error.fingerprint_hash` of the newest occurrence (FINGERPRINT only) */
    public ?string $canonicalFingerprint = null;

    public ?string $firstSeenTimestamp = null;

    public ?string $errorCode = null;

    public ?string $errorKind = null;

    public ?string $throwSiteFileName = null;

    public ?int $throwSiteLine = null;

    public ?string $environment = null;

    public ?string $workspace = null;

    /**
     * @param array<string, mixed> $bucket One decoded aggregation bucket of the Elasticsearch response
     */
    public static function buildFromBucket(
        array $bucket,
        RuntimeErrorFieldRedactor $fieldRedactor,
        ?ElasticsearchFieldNames $fieldNames = null
    ): static {
        $fieldNames ??= new ElasticsearchFieldNames();
        $runtimeErrorGroup = new static();
        // a composite (multi_terms) key is an array — detect it before any string cast
        $isCompositeKey = isset($bucket['key']) && is_array($bucket['key']);
        if ($isCompositeKey) {
            $runtimeErrorGroup->displayFingerprint = self::buildCompositeDisplayFingerprint($bucket['key']);
        } else {
            $runtimeErrorGroup->displayFingerprint = isset($bucket['key']) ? (string)$bucket['key'] : null;
        }
        $runtimeErrorGroup->count = (int)($bucket['doc_count'] ?? 0);
        $runtimeErrorGroup->affectedLocationCount = (int)($bucket['affected_locations']['value'] ?? 0);

        $latestSource = $bucket['latest']['hits']['hits'][0]['_source'] ?? [];
        $sourceString = static fn(string $field): ?string => isset($latestSource[$field]) ? (string)$latestSource[$field] : null;
        $runtimeErrorGroup->errorType = $sourceString('error.type');
        $runtimeErrorGroup->latestTimestamp = $sourceString('@timestamp');
        if (!$isCompositeKey) {
            $runtimeErrorGroup->canonicalFingerprint = $sourceString('error.fingerprint_hash');
        }
        $latestMessage = $sourceString('message');
        $runtimeErrorGroup->latestMessage = $latestMessage !== null ? $fieldRedactor->redact($latestMessage) : null;
        $stackTrace = $sourceString('error.stack_trace');
        $runtimeErrorGroup->stackTrace = $stackTrace !== null ? $fieldRedactor->redact($stackTrace) : null;
        $route = $sourceString('route_name');
        $runtimeErrorGroup->route = $route !== null ? $fieldRedactor->redact($route) : null;
        $firstSeenTimestamp = $bucket['first_seen']['value_as_string'] ?? null;
        $runtimeErrorGroup->firstSeenTimestamp = $firstSeenTimestamp !== null ? (string)$firstSeenTimestamp : null;
        $runtimeErrorGroup->errorCode = $sourceString('error.code');
        $runtimeErrorGroup->errorKind = $sourceString('error.kind');
        $runtimeErrorGroup->throwSiteFileName = $sourceString('log.origin.file.name');
        $runtimeErrorGroup->throwSiteLine = isset($latestSource['log.origin.file.line']) ? (int)$latestSource['log.origin.file.line'] : null;
        $runtimeErrorGroup->environment = $sourceString('environment');
        $runtimeErrorGroup->workspace = $sourceString($fieldNames->workspaceField);
        return $runtimeErrorGroup;
    }

    /**
     * `<part>|<part>` of a composite key.
     *
     * @param string[] $compositeKeyParts
     */
    public static function buildCompositeDisplayFingerprint(array $compositeKeyParts): string
    {
        return implode('|', array_map(static fn(mixed $keyPart): string => (string)$keyPart, $compositeKeyParts));
    }

    /** `<file>:<line>`, `<file>` or null */
    public function getThrowSite(): ?string
    {
        if ($this->throwSiteFileName === null) {
            return null;
        }
        return $this->throwSiteLine !== null ? $this->throwSiteFileName . ':' . $this->throwSiteLine : $this->throwSiteFileName;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->displayFingerprint ?? (string)spl_object_id($this));
    }
}
