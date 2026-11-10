<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Charts;

use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Domain\Common\Entities\Charts\UtcTimeInterval;
use DDD\Infrastructure\Base\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

class UtcTimeIntervalTest extends TestCase
{
    public function testBothEndsAreIsoUtcOnTheWire(): void
    {
        $startDate = DateTime::fromTimestamp(1893456000);
        $endDate = DateTime::fromTimestamp(1893459600);

        $serialized = (array)(new UtcTimeInterval($startDate, $endDate))->toObject(cached: false, forPersistence: false);
        $serializedPlain = (array)(new TimeInterval($startDate, $endDate))->toObject(cached: false, forPersistence: false);

        self::assertSame('2030-01-01T00:00:00Z', $serialized['startDate']);
        self::assertSame('2030-01-01T01:00:00Z', $serialized['endDate']);
        self::assertSame('2030-01-01 00:00:00', $serializedPlain['startDate'], 'the plain interval keeps its format');
    }

    public function testAnOpenEndStaysOpen(): void
    {
        $serialized = (array)(new UtcTimeInterval(DateTime::fromTimestamp(1893456000)))->toObject(cached: false, forPersistence: false);

        self::assertSame('2030-01-01T00:00:00Z', $serialized['startDate']);
        self::assertArrayNotHasKey('endDate', $serialized);
    }
}
