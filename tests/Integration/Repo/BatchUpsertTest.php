<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Repo;

use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRows;
use DDD\Tests\Integration\Fixtures\Entities\FixtureSettings;
use DDD\Tests\Integration\Fixtures\Entities\SpatialRows\SpatialRow;
use DDD\Tests\Integration\Fixtures\Entities\SpatialRows\SpatialRows;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRowModel;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRows;
use DDD\Tests\Integration\Fixtures\Repo\DB\SpatialRows\DBSpatialRows;
use DDD\Tests\Integration\IntegrationTestCase;
use Throwable;

/**
 * DatabaseRepoEntitySet::batchUpdate() → DoctrineEntityManager::upsertMultiple(): stored values equal the input, an
 * omitted preserved property keeps its value, a batch of several statements is all or nothing, and the Radbonus
 * heatmap shape (no id, business-key UNIQUE, incrementing onUpdateAction) counts as before.
 */
class BatchUpsertTest extends IntegrationTestCase
{
    protected function batchUpdateCounterRows(CounterRow ...$counterRows): void
    {
        $counterRowSet = new CounterRows();
        $counterRowSet->add(...$counterRows);
        (new DBCounterRows())->batchUpdate($counterRowSet);
    }

    protected function newCounterRow(string $businessKey, array $properties = []): CounterRow
    {
        $counterRow = new CounterRow();
        $counterRow->businessKey = $businessKey;
        foreach ($properties as $propertyName => $value) {
            $counterRow->$propertyName = $value;
        }
        return $counterRow;
    }

    protected function storedCounterRowsByBusinessKey(): array
    {
        $rowsByBusinessKey = [];
        foreach (static::connection()->fetchAllAssociative('SELECT * FROM `CounterRows`') as $row) {
            $rowsByBusinessKey[$row['businessKey']] = $row;
        }
        return $rowsByBusinessKey;
    }

    public function testARowWithANullGeometryStoresTheFollowingColumnsAsSent(): void
    {
        $spatialRow = new SpatialRow();
        $spatialRow->label = 'no location';
        $spatialRow->location = null;
        $spatialRow->embedding = new Vector([0.5, 0.25, 0.125]);
        $spatialRow->flag = true;
        $payload = new FixtureSettings();
        $payload->color = 'red';
        $payload->size = 3;
        $spatialRow->payload = $payload;
        $spatialRow->amount = 42;
        $spatialRowSet = new SpatialRows();
        $spatialRowSet->add($spatialRow);

        (new DBSpatialRows())->batchUpdate($spatialRowSet);

        $storedRow = static::connection()->fetchAssociative(
            'SELECT label, location, flag, payload, amount, VEC_ToText(embedding) AS embedding FROM `SpatialRows`'
        );
        $this->assertNull($storedRow['location']);
        $this->assertSame(1, (int)$storedRow['flag']);
        $this->assertSame(['color' => 'red', 'size' => 3], array_intersect_key(json_decode($storedRow['payload'], true), ['color' => 1, 'size' => 1]));
        $this->assertSame(42, (int)$storedRow['amount']);
        $this->assertSame([0.5, 0.25, 0.125], json_decode($storedRow['embedding'], true));
    }

    public function testAnOmittedPreservedPropertyKeepsTheStoredValue(): void
    {
        static::insertRow('CounterRows', ['businessKey' => 'kept', 'displayOrder' => 9, 'amount' => 1]);
        $storedId = (int)static::connection()->lastInsertId();
        $payload = (object)['id' => $storedId, 'businessKey' => 'kept', 'amount' => 2];
        $counterRow = new CounterRow();
        $counterRow->setPropertiesFromObject($payload);

        $this->batchUpdateCounterRows($counterRow);

        $storedRow = $this->storedCounterRowsByBusinessKey()['kept'];
        $this->assertSame(2, (int)$storedRow['amount']);
        $this->assertSame(9, (int)$storedRow['displayOrder']);
    }

    public function testAMixedBatchWhoseSecondStatementFailsLeavesNoRow(): void
    {
        $firstRun = $this->newCounterRow('first', ['amount' => 1]);
        // a different shape (label set) → second statement; a label longer than VARCHAR(255) fails in strict mode
        $secondRun = $this->newCounterRow('second', ['label' => str_repeat('x', 300)]);

        try {
            $this->batchUpdateCounterRows($firstRun, $secondRun);
            $this->fail('the second statement must fail');
        } catch (Throwable) {
        }

        $this->assertSame([], $this->storedCounterRowsByBusinessKey());
        $this->assertFalse(static::connection()->isTransactionActive());
    }

    public function testInsideACallerTransactionAFailedBatchKeepsTheCallersWriteButNoBatchRow(): void
    {
        $connection = static::connection();
        $connection->beginTransaction();
        static::insertRow('CounterRows', ['businessKey' => 'caller']);
        try {
            $this->batchUpdateCounterRows(
                $this->newCounterRow('first', ['amount' => 1]),
                $this->newCounterRow('second', ['label' => str_repeat('x', 300)])
            );
            $this->fail('the second statement must fail');
        } catch (Throwable) {
        }
        $this->assertTrue($connection->isTransactionActive(), 'the caller transaction survives');
        $connection->commit();

        $this->assertSame(['caller'], array_keys($this->storedCounterRowsByBusinessKey()));
    }

    /** @return array<string, array{0: bool}> */
    public static function inputOrders(): array
    {
        return ['id row first' => [true], 'business-key row first' => [false]];
    }

    /** @dataProvider inputOrders */
    public function testAnIdRowAndABusinessKeyRowOnOneExistingRowLeaveTheLastInputValue(bool $idRowFirst): void
    {
        static::insertRow('CounterRows', ['businessKey' => 'shared', 'amount' => 0]);
        $storedId = (int)static::connection()->lastInsertId();
        $idRow = $this->newCounterRow('shared', ['amount' => 10]);
        $idRow->id = $storedId;
        $businessKeyRow = $this->newCounterRow('shared', ['amount' => 20]);

        $idRowFirst ? $this->batchUpdateCounterRows($idRow, $businessKeyRow) : $this->batchUpdateCounterRows($businessKeyRow, $idRow);

        $expectedAmount = $idRowFirst ? 20 : 10;
        $this->assertSame($expectedAmount, (int)$this->storedCounterRowsByBusinessKey()['shared']['amount']);
        $this->assertSame(1, (int)static::connection()->fetchOne('SELECT COUNT(*) FROM `CounterRows`'));
    }

    /**
     * §5 fixtures ("Writes:" bullet) "new rows B, A keep input-order ids": a batch of brand-new rows (no id,
     * distinct business keys — one multi-row statement, no row-by-row split) gets generated ids in INPUT order,
     * not insertion order flipped by any grouping — `B` (listed first) must get the LOWER id even though
     * alphabetically `A` < `B`.
     */
    public function testNewRowsKeepInputOrderIds(): void
    {
        $this->batchUpdateCounterRows(
            $this->newCounterRow('B', ['amount' => 1]),
            $this->newCounterRow('A', ['amount' => 2])
        );

        $rows = $this->storedCounterRowsByBusinessKey();
        $this->assertLessThan((int)$rows['A']['id'], (int)$rows['B']['id'], '"B" was listed first and must get the lower id');
    }

    /**
     * §5 fixtures ("Writes:" bullet) "insertOrNull() race semantics" / architecture §4.3 "insert or lose the
     * race": a plain INSERT that returns null on a unique-key collision instead of merging onto the row that
     * won the race — unlike `upsert()`/`batchUpdate()`, which would silently fold the losing attempt's columns
     * onto the winner's row (exactly the race `ServiceTasksService::insertIfAbsent()` /
     * `TableAssignmentsService::tryAssign()` must not have).
     */
    public function testInsertOrNullLosesARaceWithoutMergingOntoTheWinner(): void
    {
        $entityManager = static::entityManager();

        $winner = new DBCounterRowModel();
        $winner->businessKey = 'race';
        $winner->amount = 1;
        $winnerId = $entityManager->insertOrNull($winner);
        $this->assertNotNull($winnerId);

        $loser = new DBCounterRowModel();
        $loser->businessKey = 'race';
        $loser->amount = 999;
        $this->assertNull($entityManager->insertOrNull($loser), 'the second insert lost the race on the UNIQUE businessKey');

        $this->assertSame(1, (int)static::connection()->fetchOne('SELECT COUNT(*) FROM `CounterRows`'));
        $this->assertSame(1, (int)$this->storedCounterRowsByBusinessKey()['race']['amount'], 'the winner row must be untouched, not merged onto');
    }

    /**
     * insertOrNull() swallows ONLY a unique-key collision — every other error throws (architecture §4.3). A
     * label over the VARCHAR(255) bound (the same forced failure `testAMixedBatchWhoseSecondStatementFailsLeavesNoRow`
     * uses) is unrelated to any unique key, so it must propagate, not come back as null.
     */
    public function testInsertOrNullThrowsOnAnUnrelatedConstraintViolation(): void
    {
        $counterRowModel = new DBCounterRowModel();
        $counterRowModel->businessKey = 'too-long-label';
        $counterRowModel->label = str_repeat('x', 300);

        $this->expectException(\Throwable::class);
        static::entityManager()->insertOrNull($counterRowModel);
    }

    public function testTheHeatmapShapeCountsAsBefore(): void
    {
        $this->batchUpdateCounterRows(
            $this->newCounterRow('cell-1', ['counter' => 2]),
            $this->newCounterRow('cell-2', ['counter' => 3])
        );
        $this->batchUpdateCounterRows(
            $this->newCounterRow('cell-1', ['counter' => 5]),
            $this->newCounterRow('cell-3', ['counter' => 1])
        );

        $storedRows = $this->storedCounterRowsByBusinessKey();
        $this->assertCount(3, $storedRows);
        $this->assertSame(7, (int)$storedRows['cell-1']['counter']);
        $this->assertSame(3, (int)$storedRows['cell-2']['counter']);
        $this->assertSame(1, (int)$storedRows['cell-3']['counter']);
    }
}
