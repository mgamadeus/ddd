<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One bucket of the error trend: its start and the number of errors in it.
 */
class RuntimeErrorTrendBucket extends ValueObject
{
    public ?string $timestamp = null;

    public int $count = 0;

    /**
     * @param array<string, mixed> $bucket One decoded `date_histogram` bucket
     */
    public static function buildFromBucket(array $bucket): static
    {
        $runtimeErrorTrendBucket = new static();
        $runtimeErrorTrendBucket->timestamp = isset($bucket['key_as_string']) ? (string)$bucket['key_as_string'] : null;
        $runtimeErrorTrendBucket->count = (int)($bucket['doc_count'] ?? 0);
        return $runtimeErrorTrendBucket;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->timestamp ?? (string)spl_object_id($this));
    }
}
