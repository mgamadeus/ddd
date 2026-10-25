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
    protected static function unsupportedOnCurrentDriverReason(): ?string
    {
        // Both methods: the round trip writes via update() (ON DUPLICATE KEY UPDATE, §6 steps 3/4); the
        // @@sql_mode assertion is MariaDB/MySQL-only (also @group mariadb) with no PostgreSQL equivalent to assert.
        return 'The entity round trip here writes via update() (ON DUPLICATE KEY UPDATE is §6 steps 3/4, not '
            . 'built yet); the @@sql_mode assertion is MariaDB/MySQL-only.';
    }

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

    /** @group mariadb `@@sql_mode` is a MariaDB/MySQL system variable; PostgreSQL has no equivalent to assert on. */
    public function testTheServerRunsWithOnlyFullGroupBy(): void
    {
        $this->assertStringContainsString('ONLY_FULL_GROUP_BY', (string)static::connection()->fetchOne('SELECT @@sql_mode'));
    }
}
