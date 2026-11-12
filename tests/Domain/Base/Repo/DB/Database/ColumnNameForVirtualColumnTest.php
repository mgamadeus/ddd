<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumn;
use PHPUnit\Framework\TestCase;

/** The entity property a virtual column maps back to */
class ColumnNameForVirtualColumnTest extends TestCase
{
    public function testThePrefixIsRemovedFromAVirtualColumnName(): void
    {
        $this->assertSame('battleTeamId', DatabaseVirtualColumn::getColumnNameForVirtualColumn('virtualBattleTeamId'));
        $this->assertSame(
            'height',
            DatabaseVirtualColumn::getColumnNameForVirtualColumn(DatabaseVirtualColumn::getVirtualColumnName('height'))
        );
    }

    public function testANameWithoutThePrefixIsReturnedUnchanged(): void
    {
        $this->assertSame('height', DatabaseVirtualColumn::getColumnNameForVirtualColumn('height'));
    }
}
