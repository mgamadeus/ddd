<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Copy;

use DDD\Domain\Base\Repo\DB\Copy\CopyValueCodec;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use PHPUnit\Framework\TestCase;

/**
 * A geometry copied into a geometry(…,<srid>) column carries that SRID: the plain WKB MariaDB reads becomes EWKB. A
 * column without SRID keeps the WKB as read.
 */
class CopyValueCodecSridTest extends TestCase
{
    /** POINT(13.405 52.52), little endian WKB */
    protected const string POINT_WKB_HEX = '01010000008FC2F5285CCF2A40C3F5285C8F424A40';

    public function testAGeometryForAColumnWithAnSridBecomesEwkb(): void
    {
        $column = new DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_POINT, srid: DatabaseColumn::SRID_WGS84);
        $column->name = 'position';

        $copiedValue = CopyValueCodec::canonicalizeSourceValueForCopy($column, self::POINT_WKB_HEX, 'Trips', 1);

        // type word with the SRID flag (0x20000001), then SRID 4326 (0x10E6), then the coordinates
        $this->assertSame('0101000020E61000008FC2F5285CCF2A40C3F5285C8F424A40', $copiedValue);
    }

    public function testAGeometryForAColumnWithoutSridStaysWkb(): void
    {
        $column = new DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_POINT);
        $column->name = 'position';

        $this->assertSame(
            self::POINT_WKB_HEX,
            CopyValueCodec::canonicalizeSourceValueForCopy($column, self::POINT_WKB_HEX, 'Trips', 1)
        );
    }
}
