<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Harness;

use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use PHPUnit\Framework\TestCase;

/**
 * §6 step 1 gate: "under pdo_pgsql the harness smoke runs". Deliberately NOT an {@see IntegrationTestCase} subclass
 * — that base class's setUp()/tearDownAfterClass() manage MariaDB-only fixture tables (step 4a adds the PostgreSQL
 * ones), which this test must not depend on: it only proves the configured driver can open a connection and run a
 * trivial statement, on either engine, through the same `EntityManagerFactory::create()` path every other test uses
 * (dialect resolution, `connectionParams()`, the unreleased-code guard).
 */
class ConnectionBootTest extends TestCase
{
    protected function setUp(): void
    {
        $driver = getenv('DDD_TEST_DRIVER') ?: ($_SERVER['DDD_TEST_DRIVER'] ?? null);
        if (!in_array($driver, ['pdo_mysql', 'pdo_pgsql'], true)) {
            $this->markTestSkipped(
                'Integration suite: start tests/Integration/docker-compose.yml and set DDD_TEST_DRIVER=pdo_mysql|pdo_pgsql'
            );
        }
    }

    public function testTheConfiguredDriverBootsAConnection(): void
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $this->assertSame(1, (int)$connection->fetchOne('SELECT 1'));
    }
}
