<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Whether the registry job and the log pipeline are alive — silence must never look like "no errors". One per
 * environment ({@see self::$environment}), and the overall one with the environments in {@see self::$environments}:
 * stalled or silent when one environment is.
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
        /** @var string|null The environment; null = the overall health */
        public readonly ?string $environment = null,
        /** @var IssuesRegistryHealths|null The overall health's per-environment healths */
        public readonly ?IssuesRegistryHealths $environments = null,
    ) {
        parent::__construct();
    }

    /**
     * The overall health: stalled / silent when one environment is; the oldest successful run, the newest event, the
     * first error.
     */
    public static function fromEnvironmentHealths(IssuesRegistryHealths $issuesRegistryHealths): static
    {
        $lastSuccessfulRunAt = null;
        $newestEventSeenAt = null;
        $lastError = null;
        $jobStalled = false;
        $collectorSilent = false;
        foreach ($issuesRegistryHealths->getElements() as $issuesRegistryHealth) {
            if ($issuesRegistryHealth->lastSuccessfulRunAt !== null
                && ($lastSuccessfulRunAt === null || $issuesRegistryHealth->lastSuccessfulRunAt->getTimestamp() < $lastSuccessfulRunAt->getTimestamp())) {
                $lastSuccessfulRunAt = $issuesRegistryHealth->lastSuccessfulRunAt;
            }
            if ($issuesRegistryHealth->newestEventSeenAt !== null
                && ($newestEventSeenAt === null || $issuesRegistryHealth->newestEventSeenAt->getTimestamp() > $newestEventSeenAt->getTimestamp())) {
                $newestEventSeenAt = $issuesRegistryHealth->newestEventSeenAt;
            }
            $lastError ??= $issuesRegistryHealth->lastError !== null
                ? $issuesRegistryHealth->environment . ': ' . $issuesRegistryHealth->lastError
                : null;
            $jobStalled = $jobStalled || $issuesRegistryHealth->jobStalled;
            $collectorSilent = $collectorSilent || $issuesRegistryHealth->collectorSilent;
        }
        return new static(true, $lastSuccessfulRunAt, $newestEventSeenAt, $lastError, $jobStalled, $collectorSilent, null, $issuesRegistryHealths);
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
