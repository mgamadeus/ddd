<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Charts;

use DDD\Domain\Common\Entities\Charts\DateTimeValueSequence;
use DDD\Domain\Common\Entities\Workers\WorkerTransportHistory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

class DateTimeValueSequenceGapsTest extends TestCase
{
    /** 2030-01-01 00:00:00 UTC */
    public const int WINDOW_START = 1893456000;

    public function testAnEmptyBucketIsZeroUnlessGapsAreAskedFor(): void
    {
        $from = DateTime::fromTimestamp(self::WINDOW_START);
        $to = DateTime::fromTimestamp(self::WINDOW_START + 180);
        $valuesByTimestamp = [self::WINDOW_START + 60 => 4];

        $counts = DateTimeValueSequence::fromValuesByTimestamp('handled', $from, $to, 60, $valuesByTimestamp);
        $measure = DateTimeValueSequence::fromValuesByTimestamp('waiting', $from, $to, 60, $valuesByTimestamp, true);

        self::assertSame([0.0, 4.0, 0.0, 0.0], array_map(static fn($dateTimeValue) => $dateTimeValue->y, $counts->getElements()));
        self::assertSame([null, 4.0, null, null], array_map(static fn($dateTimeValue) => $dateTimeValue->y, $measure->getElements()));
        self::assertSame([self::WINDOW_START + 60 => 4.0], $measure->getValuesByTimestamp(), 'a gap is not a value');
        self::assertSame(
            ['x' => '2030-01-01T00:00:00Z', 'y' => null],
            $measure->getElements()[0]->toObject(),
            'the gap is on the wire as null, not left out'
        );
    }

    public function testAHistoryKnowsWhichOfItsSequencesHaveGaps(): void
    {
        $from = DateTime::fromTimestamp(self::WINDOW_START);
        $to = DateTime::fromTimestamp(self::WINDOW_START + 120);
        $workerTransportHistory = new WorkerTransportHistory('send_mail');
        $workerTransportHistory->addSequence(WorkerTransportHistory::SEQUENCE_HANDLED, $from, $to, 60, []);
        $workerTransportHistory->addSequence(WorkerTransportHistory::SEQUENCE_WAITING, $from, $to, 60, [self::WINDOW_START => 0]);
        $workerTransportHistory->addSequence(WorkerTransportHistory::SEQUENCE_DURATION_AVERAGE_MS, $from, $to, 60, []);

        self::assertSame([0.0, 0.0, 0.0], array_map(static fn($dateTimeValue) => $dateTimeValue->y, $workerTransportHistory->getSequence(WorkerTransportHistory::SEQUENCE_HANDLED)->getElements()));
        self::assertSame(
            [0.0, null, null],
            array_map(static fn($dateTimeValue) => $dateTimeValue->y, $workerTransportHistory->getSequence(WorkerTransportHistory::SEQUENCE_WAITING)->getElements()),
            'a counted empty queue is 0, a minute without a sample is a gap'
        );
        self::assertSame([null, null, null], array_map(static fn($dateTimeValue) => $dateTimeValue->y, $workerTransportHistory->getSequence(WorkerTransportHistory::SEQUENCE_DURATION_AVERAGE_MS)->getElements()));
        self::assertFalse(WorkerTransportHistory::isGapSequence(WorkerTransportHistory::SEQUENCE_PROCESS_STARTS));
    }
}
