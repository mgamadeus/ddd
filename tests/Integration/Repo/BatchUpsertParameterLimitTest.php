<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Repo;

use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertBuilder;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRows;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRows;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * A batch upsert whose bound parameters exceed {@see UpsertBuilder::MAX_BOUND_PARAMETERS_PER_STATEMENT} is split into
 * several statements and writes every row — inserts and updates, on MariaDB (error 1390 otherwise) and PostgreSQL
 * ("number of parameters must be between 0 and 65535" otherwise).
 */
class BatchUpsertParameterLimitTest extends IntegrationTestCase
{
    /** 12 000 rows × 6 bound columns = 72 000 parameters */
    protected const int ROW_COUNT = 12000;

    protected function batchUpdateCounterRows(string $labelPrefix): void
    {
        $counterRows = new CounterRows();
        for ($rowNumber = 1; $rowNumber <= self::ROW_COUNT; $rowNumber++) {
            $counterRow = new CounterRow();
            $counterRow->businessKey = 'limit-' . $rowNumber;
            $counterRow->counter = 1;
            $counterRow->label = $labelPrefix . $rowNumber;
            $counterRow->amount = $rowNumber;
            $counterRows->add($counterRow);
        }
        (new DBCounterRows())->batchUpdate($counterRows);
    }

    public function testABatchAboveTheParameterLimitWritesEveryRow(): void
    {
        // 2 × 12 000 entities with their row writes need ~200 MB on PostgreSQL, above the CLI default of 128 MB
        ini_set('memory_limit', '512M');
        $this->batchUpdateCounterRows('first ');
        $this->batchUpdateCounterRows('second ');

        $counterRowsTable = static::quoteIdentifier('CounterRows');
        $summary = static::connection()->fetchAssociative(
            'SELECT COUNT(*) AS row_count, MIN(' . static::quoteIdentifier('counter') . ') AS min_counter, MAX('
            . static::quoteIdentifier('counter') . ') AS max_counter, SUM(' . static::quoteIdentifier('amount') . ') AS amount_sum'
            . ' FROM ' . $counterRowsTable
        );
        $this->assertSame(self::ROW_COUNT, (int)$summary['row_count']);
        // the second batch hit every row: the counter's onUpdateAction added 1 to each
        $this->assertSame(2, (int)$summary['min_counter']);
        $this->assertSame(2, (int)$summary['max_counter']);
        $this->assertSame(self::ROW_COUNT * (self::ROW_COUNT + 1) / 2, (int)$summary['amount_sum']);
        $lastLabel = static::connection()->fetchOne(
            'SELECT ' . static::quoteIdentifier('label') . ' FROM ' . $counterRowsTable . ' WHERE '
            . static::quoteIdentifier('businessKey') . ' = ?',
            ['limit-' . self::ROW_COUNT]
        );
        $this->assertSame('second ' . self::ROW_COUNT, $lastLabel);
    }
}
