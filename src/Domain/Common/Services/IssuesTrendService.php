<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\Query\IssueTrend;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * Error trends from the log backend, for the charts of an issues admin and `app:issues:trend`: per environment (its
 * selector) or summed over all, optionally one group. Error events only (the registry job's filter); gap-free buckets.
 * Results are cached for {@see self::CACHE_TTL_SECONDS} under the step-aligned window, so a dashboard that reloads
 * does not query the backend every time.
 */
class IssuesTrendService extends Service
{
    public const int CACHE_TTL_SECONDS = 60;

    public const string CACHE_KEY_PREFIX = 'ddd_issues_trend_';

    protected bool $cacheEnabled = true;

    /** Turns the 60-second cache off (tests, a live view) or on */
    public function setCacheEnabled(bool $cacheEnabled): static
    {
        $this->cacheEnabled = $cacheEnabled;
        return $this;
    }

    /**
     * @param string|null $environment A configured environment; null = all environments summed
     * @param string|null $groupHash One group; null = every error
     * @param int|null $stepSeconds Bucket size; null = automatic ({@see IssueTrend::resolveAutoStepSeconds()})
     * @throws InternalErrorException Unknown environment, or the backend failed
     */
    public function getTrend(
        ?string $environment,
        ?string $groupHash,
        DateTime $from,
        DateTime $to,
        ?int $stepSeconds = null
    ): IssueTrend {
        /** @var IssuesRegistryJobService $issuesRegistryJobService */
        $issuesRegistryJobService = DDDService::instance()->getService(IssuesRegistryJobService::class);
        $stepSeconds = IssueTrend::limitStepSeconds($from, $to, $stepSeconds ?? IssueTrend::resolveAutoStepSeconds($from, $to));
        $environments = $environment !== null ? [$environment] : $issuesRegistryJobService->getEnvironmentNames();
        $cacheKey = self::CACHE_KEY_PREFIX . md5(implode('|', [
            implode(',', $environments),
            (string)$groupHash,
            IssueTrend::alignToStep($from->getTimestamp(), $stepSeconds),
            IssueTrend::alignToStep($to->getTimestamp(), $stepSeconds),
            $stepSeconds,
        ]));
        $countsByBucketStart = $this->readCache($cacheKey);
        if ($countsByBucketStart === null) {
            $countsByBucketStart = [];
            foreach ($environments as $trendEnvironment) {
                $environmentTrend = $issuesRegistryJobService->getQueryBackend($trendEnvironment)->trend($from, $to, $stepSeconds, $groupHash);
                foreach ($environmentTrend->getCountsByBucketStart() as $bucketStart => $count) {
                    $countsByBucketStart[$bucketStart] = ($countsByBucketStart[$bucketStart] ?? 0) + $count;
                }
            }
            $this->writeCache($cacheKey, $countsByBucketStart);
        }
        $issueTrend = IssueTrend::fromCounts($from, $to, $stepSeconds, $countsByBucketStart);
        $issueTrend->environment = $environment;
        $issueTrend->groupHash = $groupHash;
        return $issueTrend;
    }

    /** @return array<int, int>|null The cached counts; null = not cached (or no cache) */
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

    /** @param array<int, int> $countsByBucketStart */
    protected function writeCache(string $cacheKey, array $countsByBucketStart): void
    {
        if (!$this->cacheEnabled) {
            return;
        }
        try {
            Cache::instance()->set($cacheKey, $countsByBucketStart, self::CACHE_TTL_SECONDS);
        } catch (Throwable) {
            // without a cache the trend is just queried every time
        }
    }
}
