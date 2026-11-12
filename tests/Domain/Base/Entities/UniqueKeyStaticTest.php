<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities;

use DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRow;
use PHPUnit\Framework\TestCase;

/** The id 0 is an id like any other; only null and '' give the bare class name. */
class UniqueKeyStaticTest extends TestCase
{
    public function testZeroIsAnIdAndDiffersFromNoId(): void
    {
        $this->assertSame(ChangeHistoryRow::class . '_0', ChangeHistoryRow::uniqueKeyStatic('0'));
        $this->assertSame(ChangeHistoryRow::class . '_0', ChangeHistoryRow::uniqueKeyStatic(0));
        $this->assertSame(ChangeHistoryRow::class, ChangeHistoryRow::uniqueKeyStatic(''));
        $this->assertSame(ChangeHistoryRow::class, ChangeHistoryRow::uniqueKeyStatic(null));
        $this->assertSame(ChangeHistoryRow::class . '_7', ChangeHistoryRow::uniqueKeyStatic(7));
    }
}
