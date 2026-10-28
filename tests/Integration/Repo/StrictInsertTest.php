<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Repo;

use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\Fixtures\Entities\FixtureSettings;
use DDD\Tests\Integration\Fixtures\Entities\TranslatedRows\TranslatedRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRowModel;
use DDD\Tests\Integration\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * insert() is a strict INSERT next to the upsert update(): a collision on a unique key or the primary key throws
 * UniqueConstraintViolationException instead of merging onto the stored row, and an entity with an id never turns
 * into an update. Same on MariaDB (no ON DUPLICATE KEY UPDATE) and PostgreSQL (no ON CONFLICT).
 */
class StrictInsertTest extends IntegrationTestCase
{
    protected function newCounterRow(string $businessKey, string $label): CounterRow
    {
        $counterRow = new CounterRow();
        $counterRow->businessKey = $businessKey;
        $counterRow->label = $label;
        $counterRow->amount = 7;
        $counterRow->counter = 1;
        $counterRow->keptValue = 1;
        $counterRow->occurredAt = DateTime::fromString('2026-10-28 09:15:00');
        $counterRow->dueOn = null;
        return $counterRow;
    }

    /** @return array<string, mixed>|false the stored row */
    protected function storedRow(string $businessKey): array|false
    {
        return static::connection()->fetchAssociative(
            'SELECT * FROM ' . static::quoteIdentifier('CounterRows') . ' WHERE ' . static::quoteIdentifier('businessKey') . ' = ?',
            [$businessKey]
        );
    }

    protected function rowCount(): int
    {
        return (int)static::connection()->fetchOne('SELECT COUNT(*) FROM ' . static::quoteIdentifier('CounterRows'));
    }

    public function testInsertAssignsTheIdAndPersistsTheMappedColumns(): void
    {
        $counterRow = $this->newCounterRow('first', 'original');

        $counterRow->insert();

        $this->assertIsInt($counterRow->id);
        $storedRow = $this->storedRow('first');
        $this->assertSame($counterRow->id, (int)$storedRow['id']);
        $this->assertSame('original', $storedRow['label']);
        $this->assertSame(7, (int)$storedRow['amount']);
        $this->assertSame('2026-10-28 09:15:00', substr((string)$storedRow['occurredAt'], 0, 19));
        $this->assertNull($storedRow['dueOn']);
    }

    public function testInsertWritesAJsonValueObjectColumn(): void
    {
        $translatedRow = new TranslatedRow();
        $translatedRow->settings = new FixtureSettings();
        $translatedRow->settings->color = 'red';
        $translatedRow->settings->size = 3;

        $translatedRow->insert();

        $settings = static::connection()->fetchOne(
            'SELECT ' . static::quoteIdentifier('settings') . ' FROM ' . static::quoteIdentifier('TranslatedRows') . ' WHERE '
            . static::quoteIdentifier('id') . ' = ?',
            [$translatedRow->id]
        );
        $storedSettings = json_decode((string)$settings, true);
        $this->assertSame('red', $storedSettings['color']);
        $this->assertSame(3, $storedSettings['size']);
    }

    /** The upsert merges onto the row of the same business key; the strict insert refuses and leaves it untouched. */
    public function testASecondInsertWithTheSameUniqueKeyThrowsAndLeavesTheFirstRowUnchanged(): void
    {
        $this->newCounterRow('shared', 'original')->insert();

        try {
            $this->newCounterRow('shared', 'second')->insert();
            $this->fail('the duplicate business key must throw');
        } catch (UniqueConstraintViolationException) {
        }

        $this->assertSame('original', $this->storedRow('shared')['label']);
        $this->assertSame(1, $this->rowCount());

        // the difference to update(): the upsert overwrites the stored row of that business key
        $this->newCounterRow('shared', 'overwritten by upsert')->update();
        $this->assertSame('overwritten by upsert', $this->storedRow('shared')['label']);
        $this->assertSame(1, $this->rowCount());
    }

    public function testInsertWithAnExistingPrimaryKeyThrowsAndNeverUpdates(): void
    {
        $existingRow = $this->newCounterRow('existing', 'original');
        $existingRow->insert();

        $duplicateIdRow = $this->newCounterRow('other-key', 'should not land');
        $duplicateIdRow->id = $existingRow->id;
        try {
            $duplicateIdRow->insert();
            $this->fail('the duplicate primary key must throw');
        } catch (UniqueConstraintViolationException) {
        }

        $this->assertSame('original', $this->storedRow('existing')['label']);
        $this->assertFalse($this->storedRow('other-key'));
    }

    public function testInsertWithAnUnusedIdInsertsThatId(): void
    {
        $counterRow = $this->newCounterRow('given-id', 'original');
        $counterRow->id = 4242;

        $counterRow->insert();

        $this->assertSame(4242, (int)$this->storedRow('given-id')['id']);
        // the sequence still hands out ids above it (PostgreSQL identity catch-up)
        $nextRow = $this->newCounterRow('after-given-id', 'next');
        $nextRow->insert();
        $this->assertGreaterThan(4242, $nextRow->id);
    }

    /**
     * The retry pattern inside one transaction: the collision reverts only the failed insert, the transaction stays
     * usable (on PostgreSQL a failed statement would otherwise abort it) and the retry with the next key commits.
     */
    public function testACollisionInsideACallerTransactionCanBeRetriedInTheSameTransaction(): void
    {
        $this->newCounterRow('sequence-1', 'first writer')->insert();

        $connection = EntityManagerFactory::getInstance()->getConnection();
        $connection->beginTransaction();
        try {
            try {
                $this->newCounterRow('sequence-1', 'second writer')->insert();
                $this->fail('the duplicate must throw');
            } catch (UniqueConstraintViolationException) {
            }
            $this->newCounterRow('sequence-2', 'second writer')->insert();
            $connection->commit();
        } catch (\Throwable $throwable) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $throwable;
        }

        $this->assertSame('first writer', $this->storedRow('sequence-1')['label']);
        $this->assertSame('second writer', $this->storedRow('sequence-2')['label']);
    }

    /** Create rights work as on the upsert's insert branch: a row outside them is refused and reverted. */
    public function testTheInsertedRowMustBeVisibleUnderTheCreateRights(): void
    {
        $alias = DBCounterRowModel::MODEL_ALIAS;
        $entityManager = EntityManagerFactory::getInstance();

        $permittedModel = new DBCounterRowModel();
        $permittedModel->businessKey = 'allowed-1';
        $createRightsQueryBuilder = DBCounterRow::createQueryBuilder(true);
        $createRightsQueryBuilder->andWhere("$alias.businessKey LIKE 'allowed-%'");
        $entityManager->insert($permittedModel, $createRightsQueryBuilder);
        $this->assertNotFalse($this->storedRow('allowed-1'));

        $refusedModel = new DBCounterRowModel();
        $refusedModel->businessKey = 'denied-1';
        try {
            $entityManager->insert($refusedModel, $createRightsQueryBuilder);
            $this->fail('a row outside the create rights must be refused');
        } catch (ForbiddenException) {
        }
        $this->assertFalse($this->storedRow('denied-1'));
    }
}
