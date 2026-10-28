<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\Query\IssueTrend;
use PHPUnit\Framework\TestCase;

/**
 * Trend buckets: aligned to the step, gap-free, counts booked to their bucket; the automatic and the limited step.
 */
class IssueTrendTest extends TestCase
{
    public function testBucketsAreAlignedAndGapFree(): void
    {
        $from = DateTime::fromTimestamp(1761645630);   // 10:00:30
        $to = DateTime::fromTimestamp(1761645630 + 299);   // 10:05:29 — the 10:05 bucket is the last

        $issueTrend = IssueTrend::fromCounts($from, $to, 60, [1761645600 => 2, 1761645725 => 3, 1761645726 => 1]);

        self::assertSame(6, $issueTrend->count());
        self::assertSame(1761645600, $issueTrend->first()->start->getTimestamp());
        self::assertSame([1761645600 => 2, 1761645660 => 0, 1761645720 => 4, 1761645780 => 0, 1761645840 => 0, 1761645900 => 0], $issueTrend->getCountsByBucketStart());
        self::assertSame(6, $issueTrend->getTotal());
    }

    public function testAutomaticAndLimitedStep(): void
    {
        $now = DateTime::fromTimestamp(1761645600);
        $before = static fn(int $seconds): DateTime => DateTime::fromTimestamp(1761645600 - $seconds);

        self::assertSame(60, IssueTrend::resolveAutoStepSeconds($before(1800), $now));
        self::assertSame(600, IssueTrend::resolveAutoStepSeconds($before(6 * 3600), $now));
        self::assertSame(3600, IssueTrend::resolveAutoStepSeconds($before(24 * 3600), $now));
        self::assertSame(21600, IssueTrend::resolveAutoStepSeconds($before(7 * 86400), $now));
        // one-minute buckets over 30 days would be 43 200: coarsened to at most 2 000 buckets
        self::assertSame(1296, IssueTrend::limitStepSeconds($before(30 * 86400), $now, 60));
    }
}
