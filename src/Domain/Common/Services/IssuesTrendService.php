<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Domain\Common\Entities\Issues\IssuesTrendResult;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * Error trends from the log backend, for the charts of an issues admin and `app:issues:trend`: a
 * {@see DateTimeValueSequence} (step-aligned, gap-free, the step on the sequence) per environment (its selector) or
 * summed over all, optionally of one group. Error events only (the registry job's filter). Results are cached for
 * {@see self::CACHE_TTL_SECONDS} under the step-aligned window, so a reloading dashboard does not query the backend
 * every time.
 */
class IssuesTrendService extends Service
{
    public const int CACHE_TTL_SECONDS = 60;

    public const string CACHE_KEY_PREFIX = 'ddd_issues_trend_';

    /** The automatic steps: 1 min up to 1 h, 10 min up to 6 h, 1 h up to 24 h, then 6 h */
    public const int STEP_ONE_MINUTE = 60;
    public const int STEP_TEN_MINUTES = 600;
    public const int STEP_ONE_HOUR = 3600;
    public const int STEP_SIX_HOURS = 21600;

    /** Upper bound of buckets per trend (a step too fine for the window is coarsened) */
    public const int MAX_BUCKETS = 2000;

    /** Name of a trend over all environments and groups */
    public const string ALL_ERRORS_NAME = 'all';

    protected bool $cacheEnabled = true;

    /** Turns the 60-second cache off (tests, a live view) or on */
    public function setCacheEnabled(bool $cacheEnabled): static
    {
        $this->cacheEnabled = $cacheEnabled;
        return $this;
    }

    /** The step for a window: 1m (≤ 1 h), 10m (≤ 6 h), 1h (≤ 24 h), 6h */
    public static function resolveAutoStepSeconds(TimeInterval $interval): int
    {
        $windowSeconds = max(1, (int)$interval->getDurationSeconds());
        return match (true) {
            $windowSeconds <= 3600 => self::STEP_ONE_MINUTE,
            $windowSeconds <= 6 * 3600 => self::STEP_TEN_MINUTES,
            $windowSeconds <= 24 * 3600 => self::STEP_ONE_HOUR,
            default => self::STEP_SIX_HOURS,
        };
    }

    /** The step itself, or coarser so that the window has at most {@see self::MAX_BUCKETS} buckets */
    public static function limitStepSeconds(TimeInterval $interval, int $stepSeconds): int
    {
        $windowSeconds = max(1, (int)$interval->getDurationSeconds());
        return max(1, $stepSeconds, (int)ceil($windowSeconds / self::MAX_BUCKETS));
    }

    /**
     * The trend; its name is the group hash, else the environment, else {@see self::ALL_ERRORS_NAME}.
     *
     * @param string|null $environment A configured environment; null = all environments summed
     * @param string|null $groupHash One group; null = every error
     * @param TimeInterval $interval The window; an open end is now, an open start 24 h before the end
     * @param int|null $stepSeconds Bucket size; null = automatic ({@see self::resolveAutoStepSeconds()})
     * @throws InternalErrorException Unknown environment, or the backend failed
     */
    public function getTrend(?string $environment, ?string $groupHash, TimeInterval $interval, ?int $stepSeconds = null): DateTimeValueSequence
    {
        $issuesTrendResult = $this->findTrendWithFailedEnvironments($environment, $groupHash, $interval, $stepSeconds);
        $issuesEnvironmentFailure = $issuesTrendResult->failedEnvironments->first();
        if ($issuesEnvironmentFailure !== null && $issuesTrendResult->failedEnvironments->count() === count($this->getTrendEnvironments($environment))) {
            throw new InternalErrorException($issuesEnvironmentFailure->error);
        }
        return $issuesTrendResult->trend;
    }

    /**
     * The trend summed over the environments that answered, with those whose backend failed (a timeout of one
     * environment does not hide the others). Partial trends are not cached.
     *
     * @param string|null $environment A configured environment; null = all environments summed
     * @throws InternalErrorException Unknown environment
     */
    public function findTrendWithFailedEnvironments(?string $environment, ?string $groupHash, TimeInterval $interval, ?int $stepSeconds = null): IssuesTrendResult
    {
        /** @var IssuesRegistryJobService $issuesRegistryJobService */
        $issuesRegistryJobService = DDDService::instance()->getService(IssuesRegistryJobService::class);
        $endDate = $interval->endDate ?? DateTime::fromTimestamp(time());
        $closedInterval = new TimeInterval($interval->startDate ?? DateTime::fromTimestamp($endDate->getTimestamp() - 86400), $endDate);
        $stepSeconds = self::limitStepSeconds($closedInterval, $stepSeconds ?? self::resolveAutoStepSeconds($closedInterval));
        $environments = $this->getTrendEnvironments($environment);
        $issuesTrendResult = new IssuesTrendResult();
        $cacheKey = self::CACHE_KEY_PREFIX . md5(implode('|', [
            implode(',', $environments),
            (string)$groupHash,
            DateTimeValueSequence::alignToStep($closedInterval->startDate->getTimestamp(), $stepSeconds),
            DateTimeValueSequence::alignToStep($closedInterval->endDate->getTimestamp(), $stepSeconds),
            $stepSeconds,
        ]));
        $valuesByTimestamp = $this->readCache($cacheKey);
        if ($valuesByTimestamp === null) {
            $valuesByTimestamp = [];
            foreach ($environments as $trendEnvironment) {
                try {
                    $environmentTrend = $issuesRegistryJobService->getQueryBackend($trendEnvironment)->trend($closedInterval, $stepSeconds, $groupHash);
                } catch (Throwable $throwable) {
                    $issuesTrendResult->failedEnvironments->addFailure($trendEnvironment, $throwable->getMessage());
                    continue;
                }
                foreach ($environmentTrend->getValuesByTimestamp() as $timestamp => $value) {
                    $valuesByTimestamp[$timestamp] = ($valuesByTimestamp[$timestamp] ?? 0) + $value;
                }
            }
            if ($issuesTrendResult->failedEnvironments->count() === 0) {
                $this->writeCache($cacheKey, $valuesByTimestamp);
            }
        }
        $issuesTrendResult->trend = DateTimeValueSequence::fromValuesByTimestamp(
            $groupHash ?? $environment ?? self::ALL_ERRORS_NAME,
            $closedInterval->startDate,
            $closedInterval->endDate,
            $stepSeconds,
            $valuesByTimestamp
        );
        return $issuesTrendResult;
    }

    /**
     * The environment, or every configured one.
     *
     * @return string[]
     */
    protected function getTrendEnvironments(?string $environment): array
    {
        if ($environment !== null) {
            return [$environment];
        }
        /** @var IssuesRegistryJobService $issuesRegistryJobService */
        $issuesRegistryJobService = DDDService::instance()->getService(IssuesRegistryJobService::class);
        return $issuesRegistryJobService->getEnvironmentNames();
    }

    /** @return array<int, float>|null The cached values; null = not cached (or no cache) */
    protected function readCache(string $cacheKey): ?array
    {
        if (!$this->cacheEnabled) {
            return null;
        }
        try {
            $cached = Cache::instance()->get($cacheKey);
            return is_array($cached) ? $cached : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<int, float> $valuesByTimestamp */
    protected function writeCache(string $cacheKey, array $valuesByTimestamp): void
    {
        if (!$this->cacheEnabled) {
            return;
        }
        try {
            Cache::instance()->set($cacheKey, $valuesByTimestamp, self::CACHE_TTL_SECONDS);
        } catch (Throwable) {
            // without a cache the trend is just queried every time
        }
    }
}
