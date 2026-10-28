<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\Charts;

use DDD\Domain\Common\Entities\Charts\DateTimeValue;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Domain\Common\Entities\Charts\DateTimeValueSequences;
use DDD\Domain\Common\Entities\Charts\DateValueSequence;
use DDD\Domain\Common\Entities\Charts\DateValueSequences;
use DDD\Domain\Common\Entities\Charts\IntegerKeyValue;
use DDD\Domain\Common\Entities\Charts\StringKeyValue;
use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorTrend;
use PHPUnit\Framework\TestCase;

/**
 * The generic chart types: the time series with sub-day buckets (aligned, gap-free, the step and window on it), its
 * JSON, the window, the key-value pairs, the sequence sets, and the runtime-error trend adapter.
 */
class ChartTypesTest extends TestCase
{
    public function testBucketedSeriesIsAlignedAndGapFree(): void
    {
        $from = DateTime::fromTimestamp(1761645630);   // 10:00:30 UTC
        $to = DateTime::fromTimestamp(1761645630 + 299);   // 10:05:29 — the 10:05 bucket is the last

        $dateTimeValueSequence = DateTimeValueSequence::fromValuesByTimestamp('errors', $from, $to, 60, [1761645600 => 2, 1761645725 => 3, 1761645726 => 1]);

        self::assertSame(6, $dateTimeValueSequence->count());
        self::assertSame(60, $dateTimeValueSequence->stepSeconds);
        self::assertSame(299, $dateTimeValueSequence->interval->getDurationSeconds());
        self::assertSame(
            [1761645600 => 2.0, 1761645660 => 0.0, 1761645720 => 4.0, 1761645780 => 0.0, 1761645840 => 0.0, 1761645900 => 0.0],
            $dateTimeValueSequence->getValuesByTimestamp()
        );
        self::assertSame(6.0, $dateTimeValueSequence->getTotal());
        self::assertSame('{"x":"2025-10-28T10:00:00Z","y":2}', (string)$dateTimeValueSequence->first());
    }

    public function testDayBucketsAndOrdering(): void
    {
        $dateTimeValueSequence = DateTimeValueSequence::fromValuesByTimestamp(
            'orders',
            DateTime::fromTimestamp(1761609600),   // 2025-10-28 00:00 UTC
            DateTime::fromTimestamp(1761609600 + 6 * 86400),
            86400,
            [1761609600 + 3600 => 5]
        );
        self::assertSame(7, $dateTimeValueSequence->count());
        self::assertSame(5.0, $dateTimeValueSequence->first()->y);

        $unordered = new DateTimeValueSequence('points');
        $later = new DateTimeValue(DateTime::fromTimestamp(200), 1.0);
        $earlier = new DateTimeValue(DateTime::fromTimestamp(100), 2.0);
        $unordered->add($later, $earlier);
        self::assertSame(100, $unordered->orderByDateTime()->first()->x->getTimestamp());
    }

    public function testTimeIntervalKeyValuesAndSets(): void
    {
        $timeInterval = new TimeInterval();
        self::assertNull($timeInterval->getDurationSeconds());
        $timeInterval->setStartTime($timeInterval->stringToDateTime('2026/10/28'));
        $timeInterval->setEndTime($timeInterval->stringToDateTime('2026-10-28T12:00:00Z'));
        self::assertSame('2026-10-28 00:00:00', $timeInterval->getStartTime()->format('Y-m-d H:i:s'));
        self::assertSame(43200, $timeInterval->getDurationSeconds());
        self::assertNull($timeInterval->stringToDateTime('not a date'));

        $integerKeyValue = new IntegerKeyValue('google', 12);
        $stringKeyValue = new StringKeyValue('channel', 'instagram');
        self::assertSame(12, $integerKeyValue->value);
        self::assertSame('instagram', $stringKeyValue->value);
        self::assertNotSame($integerKeyValue->uniqueKey(), (new IntegerKeyValue('facebook', 1))->uniqueKey());

        $dateValueSequences = new DateValueSequences();
        $reasons = new DateValueSequence('reason-a');
        $dateValueSequences->add($reasons);
        self::assertSame($reasons, $dateValueSequences->first());
        $dateTimeValueSequences = new DateTimeValueSequences();
        $prod = new DateTimeValueSequence('prod', 3600);
        $testing = new DateTimeValueSequence('testing', 3600);
        $dateTimeValueSequences->add($prod, $testing);
        self::assertSame(2, $dateTimeValueSequences->count());
    }

    public function testRuntimeErrorTrendAdapter(): void
    {
        $dateTimeValueSequence = DateTimeValueSequence::fromValuesByTimestamp(
            'errors',
            DateTime::fromTimestamp(1761645600),
            DateTime::fromTimestamp(1761645600 + 3599),
            1800,
            [1761645600 => 3]
        );

        $runtimeErrorTrend = RuntimeErrorTrend::fromDateTimeValueSequence($dateTimeValueSequence);

        self::assertSame(2, $runtimeErrorTrend->count());
        self::assertSame('2025-10-28T10:00:00.000Z', $runtimeErrorTrend->first()->timestamp);
        self::assertSame(3, $runtimeErrorTrend->first()->count);
    }
}
