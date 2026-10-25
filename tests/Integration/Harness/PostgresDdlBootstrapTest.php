<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Harness;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlStatement;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Step 4a gate (dual-dialect architecture plan `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md`
 * §6 row 4a): "`--group bootstrap` on `pdo_pgsql` creates every fixture table from generated output on an empty
 * PostgreSQL 18 database." Deliberately separate from {@see IntegrationTestCase}: that base class's
 * `fixtureTablesSupported()` still gates the full read/write/query suite off `pdo_pgsql` (the write and query
 * dialects are steps 4/5, not yet built) — this class only proves `PostgresDdlRenderer`'s CREATE path works,
 * end to end, against a real server. No hand-written PostgreSQL DDL anywhere in this file: every statement comes
 * from `PostgresDdlRenderer::ensure()` / `renderCreate()`.
 *
 * Run: `docker compose -f tests/Integration/docker-compose.yml up -d --wait` then
 * `DDD_TEST_DRIVER=pdo_pgsql php -d memory_limit=2G vendor/bin/phpunit -c phpunit.integration.xml --group bootstrap`.
 */
final class PostgresDdlBootstrapTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (self::currentDriver() !== 'pdo_pgsql') {
            return;
        }
        (new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType'))->setValue(null, LazyLoadRepo::DB);
    }

    protected function setUp(): void
    {
        if (self::currentDriver() !== 'pdo_pgsql') {
            $this->markTestSkipped(
                'pdo_pgsql only: start tests/Integration/docker-compose.yml and set DDD_TEST_DRIVER=pdo_pgsql'
            );
        }
    }

    private static function currentDriver(): ?string
    {
        return getenv('DDD_TEST_DRIVER') ?: ($_SERVER['DDD_TEST_DRIVER'] ?? null);
    }

    /** @group bootstrap */
    public function testEveryFixtureTableIsCreatedFromGeneratedPostgresDdl(): void
    {
        $dialect = EntityManagerFactory::getDialect();
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $renderer = $dialect->ddlRenderer();

        // Idempotent infra (extensions, collation, ddd_* functions) — must run before any CREATE TABLE that
        // references them (a trigram index, a vector column, the ddd_ci_ai collation on every text column).
        foreach ($renderer->ensure() as $statement) {
            $connection->executeStatement($statement->sql);
        }

        $databaseModels = [];
        foreach (IntegrationTestCase::FIXTURE_ENTITY_CLASSES as $fixtureEntityClass) {
            $databaseModels[] = DatabaseModel::fromEntityClass($fixtureEntityClass);
        }

        // Start from a clean slate: a previous failed run may have left tables behind on this throwaway database.
        foreach ($databaseModels as $databaseModel) {
            $connection->executeStatement(
                'DROP TABLE IF EXISTS ' . $dialect->quote($databaseModel->sqlTableName) . ' CASCADE'
            );
        }

        $statements = [];
        foreach ($databaseModels as $databaseModel) {
            foreach ($renderer->renderCreate($databaseModel) as $statement) {
                $statements[] = $statement;
            }
        }
        // None of the three fixtures has a cross-table foreign key today, but sorting by phase is the documented,
        // supported way to run a multi-table CREATE script (DdlStatement's own docblock) and costs nothing here.
        foreach (DdlStatement::sortByPhase($statements) as $statement) {
            $connection->executeStatement($statement->sql);
        }

        foreach ($databaseModels as $databaseModel) {
            $exists = $connection->fetchOne(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
                [$databaseModel->sqlTableName]
            );
            $this->assertNotFalse(
                $exists,
                "PostgresDdlRenderer did not create table \"{$databaseModel->sqlTableName}\"."
            );

            $columnCount = $connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?',
                [$databaseModel->sqlTableName]
            );
            $storedColumnCount = 0;
            foreach ($databaseModel->columns->getElements() as $column) {
                if (!$column->ignoreProperty) {
                    $storedColumnCount++;
                }
            }
            $expectedColumnCount = $storedColumnCount + $databaseModel->virtualColumns->count();
            $this->assertSame(
                $expectedColumnCount,
                (int)$columnCount,
                "Table \"{$databaseModel->sqlTableName}\" has $columnCount columns, expected $expectedColumnCount."
            );
        }

        foreach ($databaseModels as $databaseModel) {
            $connection->executeStatement(
                'DROP TABLE IF EXISTS ' . $dialect->quote($databaseModel->sqlTableName) . ' CASCADE'
            );
        }
    }
}
