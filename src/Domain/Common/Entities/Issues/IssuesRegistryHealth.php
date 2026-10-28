<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Whether the registry job and the log pipeline are alive — silence must never look like "no errors".
 */
class IssuesRegistryHealth extends ValueObject
{
    public function __construct(
        public readonly bool $backendConfigured = false,
        public readonly ?DateTime $lastSuccessfulRunAt = null,
        public readonly ?DateTime $newestEventSeenAt = null,
        public readonly ?string $lastError = null,
        /** @var bool No successful run within ISSUES_JOB_STALE_MINUTES */
        public readonly bool $jobStalled = false,
        /** @var bool No new event at all within ISSUES_COLLECTOR_SILENT_MINUTES */
        public readonly bool $collectorSilent = false,
    ) {
        parent::__construct();
    }

    public function isHealthy(): bool
    {
        return !$this->backendConfigured || (!$this->jobStalled && !$this->collectorSilent);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
