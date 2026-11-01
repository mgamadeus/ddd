<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Repo;

use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRow;
use DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRows;
use DDD\Tests\Integration\Fixtures\Repo\DB\ChangeHistoryRows\DBChangeHistoryRows;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * The ChangeHistory `created` column on update: an existing row keeps its stored creation time whatever the update
 * carries (an admin form sends no `changeHistory`); a new row gets the current time; only code that sets
 * `ChangeHistory::$overwriteCreatedAndModifiedTime` replaces it — a payload cannot.
 */
class ChangeHistoryCreatedTimeTest extends IntegrationTestCase
{
    protected const string PAST_CREATED = '2001-02-03 04:05:06';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        $connection = static::connection();
        $databaseModel = DatabaseModel::fromEntityClass(ChangeHistoryRow::class);
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
                . static::quoteIdentifier(DatabaseModel::fromEntityClass(ChangeHistoryRow::class)->sqlTableName));
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        static::connection()->executeStatement('DELETE FROM ' . static::quoteIdentifier('ChangeHistoryRows'));
    }

    /** Inserts a row and moves its `created` into the past; returns its id */
    protected function insertRowCreatedInThePast(string $label): int
    {
        $changeHistoryRow = new ChangeHistoryRow();
        $changeHistoryRow->label = $label;
        $changeHistoryRow = $changeHistoryRow->update();
        static::connection()->executeStatement(
            'UPDATE ' . static::quoteIdentifier('ChangeHistoryRows') . ' SET ' . static::quoteIdentifier('created') . ' = ? WHERE '
            . static::quoteIdentifier('id') . ' = ?',
            [self::PAST_CREATED, $changeHistoryRow->id]
        );
        static::entityManager()->clear();
        return (int)$changeHistoryRow->id;
    }

    protected function fetchStoredCreated(int $id): string
    {
        $created = (string)static::connection()->fetchOne(
            'SELECT ' . static::quoteIdentifier('created') . ' FROM ' . static::quoteIdentifier('ChangeHistoryRows') . ' WHERE '
            . static::quoteIdentifier('id') . ' = ?',
            [$id]
        );
        return substr($created, 0, 19);
    }

    /** The entity as an edit form sends it: id and fields, no changeHistory */
    protected function newFormSave(int $id, string $label): ChangeHistoryRow
    {
        $changeHistoryRow = new ChangeHistoryRow();
        $payload = (object)['id' => $id, 'label' => $label];
        $changeHistoryRow->setPropertiesFromObject($payload);
        return $changeHistoryRow;
    }

    public function testAnUpdateWithoutChangeHistoryKeepsTheStoredCreatedTime(): void
    {
        $id = $this->insertRowCreatedInThePast('before');

        $this->newFormSave($id, 'after')->update();

        self::assertSame(self::PAST_CREATED, $this->fetchStoredCreated($id));
        self::assertSame('after', static::connection()->fetchOne(
            'SELECT ' . static::quoteIdentifier('label') . ' FROM ' . static::quoteIdentifier('ChangeHistoryRows') . ' WHERE '
            . static::quoteIdentifier('id') . ' = ?',
            [$id]
        ));
    }

    public function testABatchUpdateKeepsTheStoredCreatedTimes(): void
    {
        $firstId = $this->insertRowCreatedInThePast('first');
        $secondId = $this->insertRowCreatedInThePast('second');
        $changeHistoryRows = new ChangeHistoryRows();
        $firstFormSave = $this->newFormSave($firstId, 'first edited');
        $secondFormSave = $this->newFormSave($secondId, 'second edited');
        $changeHistoryRows->add($firstFormSave, $secondFormSave);

        (new DBChangeHistoryRows())->batchUpdate($changeHistoryRows);

        self::assertSame(self::PAST_CREATED, $this->fetchStoredCreated($firstId));
        self::assertSame(self::PAST_CREATED, $this->fetchStoredCreated($secondId));
    }

    public function testANewRowGetsTheCurrentTimeAlsoWithAnExplicitId(): void
    {
        $changeHistoryRow = new ChangeHistoryRow();
        $changeHistoryRow->id = 4711;
        $changeHistoryRow->label = 'imported';
        $changeHistoryRow->update();

        $storedCreated = $this->fetchStoredCreated(4711);
        self::assertNotSame('', $storedCreated);
        self::assertLessThan(120, abs(time() - (new DateTime($storedCreated . ' UTC'))->getTimestamp()));
    }

    public function testAPayloadCannotSwitchOnTheOverwrite(): void
    {
        $id = $this->insertRowCreatedInThePast('before');
        $changeHistoryRow = new ChangeHistoryRow();
        $payload = (object)[
            'id' => $id,
            'label' => 'forged',
            'changeHistory' => (object)['createdTime' => '2030-01-01 00:00:00', 'overwriteCreatedAndModifiedTime' => true],
        ];
        $changeHistoryRow->setPropertiesFromObject($payload);

        self::assertFalse($changeHistoryRow->getChangeHistory()->overwriteCreatedAndModifiedTime);
        $changeHistoryRow->update();
        self::assertSame(self::PAST_CREATED, $this->fetchStoredCreated($id));
    }

    public function testCodeCanStillOverwriteTheCreatedTime(): void
    {
        $id = $this->insertRowCreatedInThePast('before');
        $changeHistoryRow = $this->newFormSave($id, 'migrated');
        $changeHistoryRow->getChangeHistory()->createdTime = new DateTime('1999-12-31 23:59:58');
        $changeHistoryRow->getChangeHistory()->overwriteCreatedAndModifiedTime = true;

        $changeHistoryRow->update();

        self::assertSame('1999-12-31 23:59:58', $this->fetchStoredCreated($id));
    }
}
