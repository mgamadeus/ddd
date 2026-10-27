<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerChild;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerGrandchild;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParent;

/**
 * The three TieBreaker fixture tables (parent → to-many children → to-many grandchildren), managed by the test class
 * itself instead of {@see \DDD\Tests\Integration\IntegrationTestCase::FIXTURE_ENTITY_CLASSES}: created before the
 * class, emptied before every test, dropped after the class. Parent → child → grandchild order matters for CREATE on
 * both engines (a foreign key needs the referenced table), the reverse for DROP.
 */
trait TieBreakerFixtureTablesTrait
{
    protected const array EXTRA_FIXTURE_ENTITY_CLASSES = [
        TieBreakerParent::class,
        TieBreakerChild::class,
        TieBreakerGrandchild::class,
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        // Reverse-order drop first, in case a previous run left the tables behind: a FK-referenced table cannot
        // be dropped (without CASCADE) while another table's FK still points at it.
        foreach (array_reverse(self::EXTRA_FIXTURE_ENTITY_CLASSES) as $entityClass) {
            static::dropExtraFixtureTable($entityClass);
        }
        foreach (self::EXTRA_FIXTURE_ENTITY_CLASSES as $entityClass) {
            static::createExtraFixtureTable($entityClass);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            foreach (array_reverse(self::EXTRA_FIXTURE_ENTITY_CLASSES) as $entityClass) {
                static::dropExtraFixtureTable($entityClass);
            }
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        // Reverse order: deleting a parent row while a child row's FK still references it is the same ordering
        // problem the class docblock describes for DROP, just at the row level instead of the table level.
        foreach (array_reverse(self::EXTRA_FIXTURE_ENTITY_CLASSES) as $entityClass) {
            $connection = static::connection();
            $connection->executeStatement(
                'DELETE FROM ' . static::quoteIdentifier(DatabaseModel::fromEntityClass($entityClass)->sqlTableName)
            );
        }
    }

    /** Mirrors {@see \DDD\Tests\Integration\Repo\ConflictKeyAndIdentityCatchUpTest::createExtraFixtureTable()}. */
    protected static function createExtraFixtureTable(string $entityClass): void
    {
        $connection = static::connection();
        $databaseModel = DatabaseModel::fromEntityClass($entityClass);
        if (static::currentDriver() === 'pdo_pgsql') {
            $dialect = static::dialect();
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $dialect->quote($databaseModel->sqlTableName) . ' CASCADE');
            $statements = $dialect->ddlRenderer()->renderCreate($databaseModel);
            foreach ($statements->sortedByPhase() as $statement) {
                $connection->executeStatement($statement->sql);
            }
            return;
        }
        $connection->executeStatement('DROP TABLE IF EXISTS `' . $databaseModel->sqlTableName . '`');
        foreach (static::splitSqlStatements($databaseModel->getSql()) as $statement) {
            $connection->executeStatement($statement);
        }
    }

    protected static function dropExtraFixtureTable(string $entityClass): void
    {
        $connection = static::connection();
        $databaseModel = DatabaseModel::fromEntityClass($entityClass);
        $connection->executeStatement(
            'DROP TABLE IF EXISTS ' . static::quoteIdentifier($databaseModel->sqlTableName)
            . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : '')
        );
    }

    protected function findParentIdByBusinessKey(string $businessKey): int
    {
        return (int)static::connection()->fetchOne(
            'SELECT ' . static::quoteIdentifier('id') . ' FROM ' . static::quoteIdentifier('TieBreakerParents')
            . ' WHERE ' . static::quoteIdentifier('businessKey') . ' = ?',
            [$businessKey]
        );
    }
}
