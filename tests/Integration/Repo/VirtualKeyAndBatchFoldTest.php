<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Repo;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Tests\Integration\Fixtures\Entities\ConflictRows\VirtualKeyRow;
use DDD\Tests\Integration\Fixtures\Entities\ConflictRows\VirtualKeyRows;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRows;
use DDD\Tests\Integration\Fixtures\Repo\DB\ConflictRows\DBVirtualKeyRows;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRows;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * Saves without an id merge like MariaDB's ON DUPLICATE KEY UPDATE on both engines: onto a UNIQUE key over a
 * generated column (`IFNULL(countryId, 0)`), and in a batch whose rows differ only in case or accents on a text key
 * (PostgreSQL's `ddd_ci_ai` compares them equal — one multi-row ON CONFLICT raised 21000).
 */
class VirtualKeyAndBatchFoldTest extends IntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        $connection = static::connection();
        $databaseModel = DatabaseModel::fromEntityClass(VirtualKeyRow::class);
        $connection->executeStatement('DROP TABLE IF EXISTS ' . static::quoteIdentifier($databaseModel->sqlTableName)
            . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : ''));
        if (static::currentDriver() === 'pdo_pgsql') {
            foreach (static::dialect()->ddlRenderer()->renderCreate($databaseModel)->sortedByPhase() as $statement) {
                $connection->executeStatement($statement->sql);
            }
            return;
        }
        foreach (static::splitSqlStatements($databaseModel->getSql()) as $statement) {
            $connection->executeStatement($statement);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            static::connection()->executeStatement('DROP TABLE IF EXISTS '
                . static::quoteIdentifier(DatabaseModel::fromEntityClass(VirtualKeyRow::class)->sqlTableName));
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        static::connection()->executeStatement('DELETE FROM '
            . static::quoteIdentifier(DatabaseModel::fromEntityClass(VirtualKeyRow::class)->sqlTableName));
    }

    protected function newVirtualKeyRow(string $groupKey, ?int $countryId, string $label): VirtualKeyRow
    {
        $virtualKeyRow = new VirtualKeyRow();
        $virtualKeyRow->groupKey = $groupKey;
        $virtualKeyRow->countryId = $countryId;
        $virtualKeyRow->label = $label;
        return $virtualKeyRow;
    }

    /** @return array<int, array<string, mixed>> */
    protected function storedVirtualKeyRows(): array
    {
        $dialect = static::dialect();
        return static::connection()->fetchAllAssociative(
            'SELECT ' . $dialect->quote('groupKey') . ', ' . $dialect->quote('countryId') . ', ' . $dialect->quote('label')
            . ' FROM ' . $dialect->quote('VirtualKeyRows') . ' ORDER BY ' . $dialect->quote('id')
        );
    }

    public function testASaveWithoutIdMergesOntoTheKeyOverAGeneratedColumn(): void
    {
        $this->newVirtualKeyRow('greeting', null, 'first')->update();
        static::entityManager()->clear();
        // same key: groupKey + IFNULL(countryId, 0)
        $this->newVirtualKeyRow('greeting', null, 'second')->update();
        static::entityManager()->clear();
        $this->newVirtualKeyRow('greeting', 49, 'german')->update();

        $storedRows = $this->storedVirtualKeyRows();
        self::assertCount(2, $storedRows);
        self::assertSame('second', $storedRows[0]['label']);
        self::assertSame('german', $storedRows[1]['label']);
    }

    public function testABatchWithTwoRowsOfOneGeneratedKeyMergesThem(): void
    {
        $virtualKeyRows = new VirtualKeyRows();
        $first = $this->newVirtualKeyRow('batch', null, 'first');
        $second = $this->newVirtualKeyRow('batch', null, 'second');
        $virtualKeyRows->add($first, $second);

        (new DBVirtualKeyRows())->batchUpdate($virtualKeyRows);

        $storedRows = $this->storedVirtualKeyRows();
        self::assertCount(1, $storedRows);
        self::assertSame('second', $storedRows[0]['label']);
    }

    public function testABatchWithKeysDifferingOnlyInCaseAndAccentsDoesNotFail(): void
    {
        $counterRows = new CounterRows();
        foreach (['Pizza', 'pizza', 'Café', 'Cafe'] as $amount => $businessKey) {
            $counterRow = new CounterRow();
            $counterRow->businessKey = $businessKey;
            $counterRow->amount = $amount;
            $counterRow->isActive = true;
            $counterRow->displayOrder = 0;
            $counterRows->add($counterRow);
        }

        (new DBCounterRows())->batchUpdate($counterRows);

        $storedCount = (int)static::connection()->fetchOne('SELECT COUNT(*) FROM ' . static::quoteIdentifier('CounterRows'));
        // MariaDB's utf8mb4 general collation and PostgreSQL's ddd_ci_ai both compare them equal: two rows
        self::assertSame(2, $storedCount);
    }
}
