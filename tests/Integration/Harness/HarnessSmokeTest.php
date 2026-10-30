<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Harness;

use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * The harness itself: fixture tables exist with the generator's DDL, an entity round-trips through its DB repo.
 */
class HarnessSmokeTest extends IntegrationTestCase
{
    public function testAnEntityRoundTripsThroughItsRepo(): void
    {
        $counterRow = new CounterRow();
        $counterRow->businessKey = 'smoke';
        $counterRow->counter = 3;
        $counterRow->amount = 7;
        $counterRow = $counterRow->update();

        $this->assertNotEmpty($counterRow->id);
        $storedRow = $this->fetchRow('CounterRows', $counterRow->id);
        $this->assertSame('smoke', $storedRow['businessKey']);
        $this->assertSame(7, (int)$storedRow['amount']);
        $this->assertSame(0, (int)$storedRow['displayOrder']);
    }

    /**
     * @group mariadb `@@sql_mode` is a MariaDB/MySQL system variable; PostgreSQL has no equivalent to assert on.
     * Self-skip too (RunReadOnlyQueryTest's convention) so this ONE method is robust to a run that does not pass
     * `--exclude-group mariadb` — the rest of this class runs on both engines, so the skip can't live at the
     * class level the way RunReadOnlyQueryTest's does.
     */
    public function testTheServerRunsWithOnlyFullGroupBy(): void
    {
        if (static::currentDriver() === 'pdo_pgsql') {
            $this->markTestSkipped('`@@sql_mode` is a MariaDB/MySQL system variable (@group mariadb).');
        }
        $this->assertStringContainsString('ONLY_FULL_GROUP_BY', (string)static::connection()->fetchOne('SELECT @@sql_mode'));
    }
}
