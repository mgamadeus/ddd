<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * An error trend: gap-free buckets of {@see self::$stepSeconds}, oldest first, aligned to the step (UTC), from the
 * bucket holding `from` to the one holding `to`. Empty buckets are 0.
 *
 * @property IssueTrendBucket[] $elements;
 * @method IssueTrendBucket[] getElements()
 * @method IssueTrendBucket|null first()
 */
class IssueTrend extends ObjectSet
{
    /** The automatic steps: 1 min up to 1 h, 10 min up to 6 h, 1 h up to 24 h, then 6 h */
    public const int STEP_ONE_MINUTE = 60;
    public const int STEP_TEN_MINUTES = 600;
    public const int STEP_ONE_HOUR = 3600;
    public const int STEP_SIX_HOURS = 21600;

    /** Upper bound of buckets per trend (a step too fine for the window is coarsened) */
    public const int MAX_BUCKETS = 2000;

    public int $stepSeconds = self::STEP_ONE_HOUR;

    /** The registry environment; null = all environments summed */
    public ?string $environment = null;

    /** The group; null = every error of the environment */
    public ?string $groupHash = null;

    /** The step for a window: 1m (≤ 1 h), 10m (≤ 6 h), 1h (≤ 24 h), 6h */
    public static function resolveAutoStepSeconds(DateTime $from, DateTime $to): int
    {
        $windowSeconds = max(1, $to->getTimestamp() - $from->getTimestamp());
        return match (true) {
            $windowSeconds <= 3600 => self::STEP_ONE_MINUTE,
            $windowSeconds <= 6 * 3600 => self::STEP_TEN_MINUTES,
            $windowSeconds <= 24 * 3600 => self::STEP_ONE_HOUR,
            default => self::STEP_SIX_HOURS,
        };
    }

    /** The step itself, or coarser so that the window has at most {@see self::MAX_BUCKETS} buckets */
    public static function limitStepSeconds(DateTime $from, DateTime $to, int $stepSeconds): int
    {
        $windowSeconds = max(1, $to->getTimestamp() - $from->getTimestamp());
        return max(1, $stepSeconds, (int)ceil($windowSeconds / self::MAX_BUCKETS));
    }

    /** The start of the bucket holding the timestamp */
    public static function alignToStep(int $timestamp, int $stepSeconds): int
    {
        return intdiv($timestamp, $stepSeconds) * $stepSeconds;
    }

    /**
     * The gap-free trend from counts per bucket start (an internal helper: the map comes from a backend response and
     * is wrapped right here). Counts at timestamps that are not bucket starts land in their bucket.
     *
     * @param array<int, int> $countsByTimestamp Unix timestamp → error events
     */
    public static function fromCounts(DateTime $from, DateTime $to, int $stepSeconds, array $countsByTimestamp): static
    {
        $issueTrend = new static();
        $issueTrend->stepSeconds = $stepSeconds;
        $countsByBucketStart = [];
        foreach ($countsByTimestamp as $timestamp => $count) {
            $bucketStart = self::alignToStep((int)$timestamp, $stepSeconds);
            $countsByBucketStart[$bucketStart] = ($countsByBucketStart[$bucketStart] ?? 0) + (int)$count;
        }
        $lastBucketStart = self::alignToStep($to->getTimestamp(), $stepSeconds);
        for ($bucketStart = self::alignToStep($from->getTimestamp(), $stepSeconds); $bucketStart <= $lastBucketStart; $bucketStart += $stepSeconds) {
            $issueTrendBucket = new IssueTrendBucket(DateTime::fromTimestamp($bucketStart), $countsByBucketStart[$bucketStart] ?? 0);
            $issueTrend->add($issueTrendBucket);
        }
        return $issueTrend;
    }

    /**
     * The counts per bucket start (to cache or to sum trends).
     *
     * @return array<int, int>
     */
    public function getCountsByBucketStart(): array
    {
        $countsByBucketStart = [];
        foreach ($this->getElements() as $issueTrendBucket) {
            $countsByBucketStart[$issueTrendBucket->start->getTimestamp()] = $issueTrendBucket->count;
        }
        return $countsByBucketStart;
    }

    public function getTotal(): int
    {
        return array_sum(array_map(static fn(IssueTrendBucket $issueTrendBucket): int => $issueTrendBucket->count, $this->getElements()));
    }
}
