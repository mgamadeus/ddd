<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Schema;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModels;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTableDiff;
use DDD\Domain\Base\Repo\DB\Database\Diff\DBTableDiffs;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\SqlScriptLexer;
use DDD\Domain\Common\Services\DatabaseSchemaDiffService;
use DDD\Domain\Common\Services\DatabaseSchemaIntrospectionService;
use DDD\Domain\Common\Services\PostgresSchemaIntrospectionService;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\Fixtures\Entities\SpatialRows\SpatialRow;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerChild;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerGrandchild;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParent;
use DDD\Tests\Integration\Fixtures\Entities\TranslatedRows\TranslatedRow;
use DDD\Tests\Integration\Fixtures\Entities\TriggerRows\TriggerRow;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * The schema diff on both engines: a schema created from the entities diffs empty (round trip), and each kind of drift
 * on the live side produces a diff whose apply brings the table back — the diff is empty again afterwards.
 */
class SchemaDiffTest extends IntegrationTestCase
{
    /** Tables this class drops and re-creates before every test (parent → child order) */
    protected const array MANAGED_ENTITY_CLASSES = [
        TieBreakerParent::class,
        TieBreakerChild::class,
        TieBreakerGrandchild::class,
        TriggerRow::class,
    ];

    /** Every table the diff is compared on (managed + the shared fixtures, which are only read) */
    protected const array DIFFED_ENTITY_CLASSES = [
        CounterRow::class,
        TranslatedRow::class,
        SpatialRow::class,
        TieBreakerParent::class,
        TieBreakerChild::class,
        TieBreakerGrandchild::class,
        TriggerRow::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_reverse(self::MANAGED_ENTITY_CLASSES) as $entityClass) {
            static::dropTable(DatabaseModel::fromEntityClass($entityClass)->sqlTableName);
        }
        foreach (self::MANAGED_ENTITY_CLASSES as $entityClass) {
            static::createTableFromEntity($entityClass);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            foreach (array_reverse(self::MANAGED_ENTITY_CLASSES) as $entityClass) {
                static::dropTable(DatabaseModel::fromEntityClass($entityClass)->sqlTableName);
            }
        }
        parent::tearDownAfterClass();
    }

    protected static function dropTable(string $tableName): void
    {
        static::connection()->executeStatement(
            'DROP TABLE IF EXISTS ' . static::quoteIdentifier($tableName) . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : '')
        );
    }

    protected static function createTableFromEntity(string $entityClass): void
    {
        $databaseModel = DatabaseModel::fromEntityClass($entityClass);
        if (static::currentDriver() === 'pdo_pgsql') {
            foreach (static::dialect()->ddlRenderer()->renderCreate($databaseModel)->sortedByPhase() as $statement) {
                static::connection()->executeStatement($statement->sql);
            }
            return;
        }
        foreach (SqlScriptLexer::splitStatements($databaseModel->getSql()) as $statement) {
            static::connection()->executeStatement($statement);
        }
    }

    /** A diff service whose target is the fixture entities (the generator does not scan tests/) */
    protected function diffService(): DatabaseSchemaDiffService
    {
        return new class extends DatabaseSchemaDiffService {
            protected function getTargetDatabaseModels(?array $entityClasses = null): DatabaseModels
            {
                $databaseModels = new DatabaseModels();
                foreach (SchemaDiffTest::diffedEntityClasses() as $entityClass) {
                    $databaseModel = DatabaseModel::fromEntityClass($entityClass);
                    $databaseModels->add($databaseModel);
                }
                return $databaseModels;
            }
        };
    }

    /**
     * The service as the container builds it: autowiring passes the concrete (MariaDB) introspection service, which
     * must not be used on PostgreSQL.
     */
    protected function containerLikeDiffService(): DatabaseSchemaDiffService
    {
        return new class(new DatabaseSchemaIntrospectionService()) extends DatabaseSchemaDiffService {
            protected function getTargetDatabaseModels(?array $entityClasses = null): DatabaseModels
            {
                $databaseModels = new DatabaseModels();
                foreach (SchemaDiffTest::diffedEntityClasses() as $entityClass) {
                    $databaseModel = DatabaseModel::fromEntityClass($entityClass);
                    $databaseModels->add($databaseModel);
                }
                return $databaseModels;
            }
        };
    }

    /** @return string[] */
    public static function diffedEntityClasses(): array
    {
        return self::DIFFED_ENTITY_CLASSES;
    }

    /** The diffs of the fixture tables only (other tables of the test database are not ours) */
    protected function fixtureDiffs(DatabaseSchemaDiffService $diffService): DBTableDiffs
    {
        $fixtureTableNames = array_map(
            static fn(string $entityClass): string => DatabaseModel::fromEntityClass($entityClass)->sqlTableName,
            self::DIFFED_ENTITY_CLASSES
        );
        $fixtureDiffs = new DBTableDiffs();
        foreach ($diffService->computeDiffs()->getElements() as $tableDiff) {
            if (in_array($tableDiff->sqlTableName, $fixtureTableNames, true)) {
                $fixtureDiffs->add($tableDiff);
            }
        }
        return $fixtureDiffs;
    }

    protected function describeDiffs(DBTableDiffs $tableDiffs): string
    {
        $descriptions = [];
        foreach ($tableDiffs->getElements() as $tableDiff) {
            $descriptions[] = $tableDiff->sqlTableName . ' ' . $tableDiff->changeType . ":\n" . $tableDiff->sql;
        }
        return implode("\n\n", $descriptions);
    }

    protected function diffOf(DBTableDiffs $tableDiffs, string $tableName): DBTableDiff
    {
        foreach ($tableDiffs->getElements() as $tableDiff) {
            if ($tableDiff->sqlTableName === $tableName) {
                return $tableDiff;
            }
        }
        $this->fail("no diff for $tableName:\n" . $this->describeDiffs($tableDiffs));
    }

    /** Applies the diffs and asserts the fixture tables diff empty afterwards */
    protected function applyAndAssertConverged(DBTableDiffs $tableDiffs): void
    {
        $this->diffService()->applyDiffs($tableDiffs);
        $remainingDiffs = $this->fixtureDiffs($this->diffService());
        $this->assertSame(0, $remainingDiffs->count(), "after apply:\n" . $this->describeDiffs($remainingDiffs));
    }

    protected function isPostgres(): bool
    {
        return static::currentDriver() === 'pdo_pgsql';
    }

    public function testASchemaCreatedFromTheEntitiesDiffsEmpty(): void
    {
        $tableDiffs = $this->fixtureDiffs($this->diffService());

        $this->assertSame(0, $tableDiffs->count(), $this->describeDiffs($tableDiffs));
        if ($this->isPostgres()) {
            // the scratch schema of the target build is rolled back with its transaction
            $this->assertSame(0, (int)static::connection()->fetchOne(
                "SELECT count(*) FROM pg_namespace WHERE nspname LIKE 'ddd\\_diff\\_%'"
            ));
        }
    }

    public function testTheServiceWithTheAutowiredIntrospectionDiffsEmptyOnBothEngines(): void
    {
        $tableDiffs = $this->fixtureDiffs($this->containerLikeDiffService());

        $this->assertSame(0, $tableDiffs->count(), $this->describeDiffs($tableDiffs));
    }

    /** Tables an extension owns (PostGIS spatial_ref_sys) are not the application's: never a DROP_TABLE. */
    public function testExtensionOwnedTablesAreNotLiveTables(): void
    {
        if (!$this->isPostgres()) {
            $this->markTestSkipped('Extensions are PostgreSQL objects.');
        }
        $extensionTableName = (string)static::connection()->fetchOne(
            "SELECT c.relname FROM pg_class c JOIN pg_depend d ON d.objid = c.oid AND d.classid = 'pg_class'::regclass
             WHERE d.deptype = 'e' AND c.relkind IN ('r', 'p') AND c.relnamespace = current_schema()::regnamespace LIMIT 1"
        );
        if ($extensionTableName === '') {
            $this->markTestSkipped('No extension-owned table in this database.');
        }

        $this->assertNotContains($extensionTableName, (new PostgresSchemaIntrospectionService())->getLiveTableNames());
        foreach ($this->diffService()->computeDiffs()->getElements() as $tableDiff) {
            $this->assertNotSame($extensionTableName, $tableDiff->sqlTableName);
        }
    }

    public function testAMissingColumnIsAddedBack(): void
    {
        static::connection()->executeStatement(
            'ALTER TABLE ' . static::quoteIdentifier('TieBreakerChildren') . ' DROP COLUMN ' . static::quoteIdentifier('label')
        );

        $tableDiffs = $this->fixtureDiffs($this->diffService());

        $this->assertStringContainsString('ADD COLUMN', $this->diffOf($tableDiffs, 'TieBreakerChildren')->sql);
        $this->applyAndAssertConverged($tableDiffs);
    }

    public function testAChangedTypeLengthAndNullabilityIsAlteredBack(): void
    {
        static::connection()->executeStatement($this->isPostgres()
            ? 'ALTER TABLE "TieBreakerChildren" ALTER COLUMN "label" TYPE character varying(12), ALTER COLUMN "label" SET NOT NULL'
            : 'ALTER TABLE `TieBreakerChildren` MODIFY COLUMN `label` VARCHAR(12) NOT NULL');

        $tableDiffs = $this->fixtureDiffs($this->diffService());

        $this->assertNotSame('', $this->diffOf($tableDiffs, 'TieBreakerChildren')->sql);
        $this->applyAndAssertConverged($tableDiffs);
    }

    public function testAnExtraIndexIsDroppedAndAMissingOneAdded(): void
    {
        static::connection()->executeStatement(
            'CREATE INDEX ' . static::quoteIdentifier('extraLabelIndex') . ' ON ' . static::quoteIdentifier('TieBreakerChildren')
            . ' (' . static::quoteIdentifier('label') . ', ' . static::quoteIdentifier('tieBreakerParentId') . ')'
        );
        $uniqueIndexName = (string)static::connection()->fetchOne($this->isPostgres()
            ? "SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = 'TieBreakerParents' AND indexdef LIKE '%UNIQUE%' AND indexname NOT LIKE '%_pkey'"
            : "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'TieBreakerParents' AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'");
        $this->assertNotSame('', $uniqueIndexName);
        static::connection()->executeStatement($this->isPostgres()
            ? 'DROP INDEX "' . $uniqueIndexName . '"'
            : 'ALTER TABLE `TieBreakerParents` DROP INDEX `' . $uniqueIndexName . '`');

        $tableDiffs = $this->fixtureDiffs($this->diffService());

        $this->assertStringContainsString('extraLabelIndex', $this->diffOf($tableDiffs, 'TieBreakerChildren')->sql);
        $this->assertStringContainsString('UNIQUE', $this->diffOf($tableDiffs, 'TieBreakerParents')->sql);
        $this->applyAndAssertConverged($tableDiffs);
    }

    public function testAChangedForeignKeyActionIsRecreated(): void
    {
        $constraintName = (string)static::connection()->fetchOne($this->isPostgres()
            ? "SELECT conname FROM pg_constraint WHERE contype = 'f' AND conrelid = '\"TieBreakerChildren\"'::regclass"
            : "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'TieBreakerChildren'");
        $this->assertNotSame('', $constraintName);
        static::connection()->executeStatement($this->isPostgres()
            ? 'ALTER TABLE "TieBreakerChildren" DROP CONSTRAINT "' . $constraintName . '"'
            : 'ALTER TABLE `TieBreakerChildren` DROP FOREIGN KEY `' . $constraintName . '`');
        static::connection()->executeStatement(
            'ALTER TABLE ' . static::quoteIdentifier('TieBreakerChildren') . ' ADD CONSTRAINT ' . static::quoteIdentifier($constraintName)
            . ' FOREIGN KEY (' . static::quoteIdentifier('tieBreakerParentId') . ') REFERENCES '
            . static::quoteIdentifier('TieBreakerParents') . ' (' . static::quoteIdentifier('id') . ') ON DELETE RESTRICT'
        );

        $tableDiffs = $this->fixtureDiffs($this->diffService());

        $this->assertStringContainsString('FOREIGN KEY', $this->diffOf($tableDiffs, 'TieBreakerChildren')->sql);
        $this->applyAndAssertConverged($tableDiffs);
    }

    public function testAChangedGeneratedColumnExpressionIsRebuilt(): void
    {
        $connection = static::connection();
        if ($this->isPostgres()) {
            $connection->executeStatement('ALTER TABLE "TranslatedRows" DROP COLUMN "virtualNameSearch"');
            $connection->executeStatement(
                'ALTER TABLE "TranslatedRows" ADD COLUMN "virtualNameSearch" text GENERATED ALWAYS AS (\'drifted\') STORED'
            );
        } else {
            $connection->executeStatement('ALTER TABLE `TranslatedRows` DROP COLUMN `virtualNameSearch`');
            $connection->executeStatement(
                "ALTER TABLE `TranslatedRows` ADD COLUMN `virtualNameSearch` VARCHAR(255) AS ('drifted') STORED"
            );
        }

        $tableDiffs = $this->fixtureDiffs($this->diffService());

        $this->assertStringContainsString('virtualNameSearch', $this->diffOf($tableDiffs, 'TranslatedRows')->sql);
        $this->applyAndAssertConverged($tableDiffs);
    }

    public function testAMissingTriggerIsCreatedAgain(): void
    {
        static::connection()->executeStatement($this->isPostgres()
            ? 'DROP TRIGGER "onTriggerRowInsert" ON "TriggerRows"'
            : 'DROP TRIGGER `onTriggerRowInsert`');

        $tableDiffs = $this->fixtureDiffs($this->diffService());

        $this->assertStringContainsString('onTriggerRowInsert', $this->diffOf($tableDiffs, 'TriggerRows')->sql);
        $this->applyAndAssertConverged($tableDiffs);
    }

    public function testAChangedTriggerFunctionIsReplacedOnPostgres(): void
    {
        if (!$this->isPostgres()) {
            $this->markTestSkipped('A PostgreSQL trigger body lives in its function.');
        }
        static::connection()->executeStatement(
            'CREATE OR REPLACE FUNCTION ddd_trigger_triggerrows_before_insert() RETURNS trigger LANGUAGE plpgsql AS $ddd$ BEGIN RETURN NEW; END; $ddd$'
        );

        $tableDiffs = $this->fixtureDiffs($this->diffService());

        $this->assertStringContainsString('ddd_trigger_triggerrows_before_insert', $this->diffOf($tableDiffs, 'TriggerRows')->sql);
        $this->applyAndAssertConverged($tableDiffs);
    }
}
