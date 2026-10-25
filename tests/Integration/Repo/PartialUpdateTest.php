<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Repo;

use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\Fixtures\Entities\TranslatedRows\TranslatedRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRowModel;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * updatePartialIgnoringRights() writes with a real UPDATE: the former upsert form inserted a ghost row when the row
 * had been deleted meanwhile. Both UPDATE paths (the partial one and the rights-scoped one of upsert()) bind the
 * incoming value where an onUpdateAction reads VALUES(<own column>), which is NULL outside ON DUPLICATE KEY UPDATE.
 */
class PartialUpdateTest extends IntegrationTestCase
{
    protected static function unsupportedOnCurrentDriverReason(): ?string
    {
        return 'PostgreSQL write path (upsert/merge/batch) is §6 steps 3/4 of the dual-dialect architecture '
            . 'plan, not built yet; this class exercises updatePartialIgnoringRights() and upsert() directly.';
    }

    protected function insertCounterRow(array $columns): int
    {
        static::connection()->insert('CounterRows', $columns);
        return (int)static::connection()->lastInsertId();
    }

    protected function countCounterRows(): int
    {
        return (int)static::connection()->fetchOne('SELECT COUNT(*) FROM CounterRows');
    }

    public function testAPartialWriteOnAMissingRowReturnsNullAndInsertsNothing(): void
    {
        $counterRow = new CounterRow();
        $counterRow->id = 424242;
        $counterRow->label = 'ghost';

        $rowId = (new DBCounterRow())->updatePartialIgnoringRights($counterRow, 'label');

        $this->assertNull($rowId);
        $this->assertSame(0, $this->countCounterRows());
    }

    public function testAPartialWriteWritesOnlyTheNamedColumn(): void
    {
        $rowId = $this->insertCounterRow(['businessKey' => 'a', 'label' => 'before', 'amount' => 7, 'displayOrder' => 3]);
        $counterRow = new CounterRow();
        $counterRow->id = $rowId;
        $counterRow->label = 'after';
        $counterRow->amount = 99;

        $this->assertSame($rowId, (new DBCounterRow())->updatePartialIgnoringRights($counterRow, 'label'));

        $storedRow = $this->fetchRow('CounterRows', $rowId);
        $this->assertSame('after', $storedRow['label']);
        $this->assertSame(7, (int)$storedRow['amount']);
        $this->assertSame(3, (int)$storedRow['displayOrder']);
    }

    public function testAnUnchangedPartialWriteReturnsTheId(): void
    {
        $rowId = $this->insertCounterRow(['businessKey' => 'b', 'label' => 'same']);
        $counterRow = new CounterRow();
        $counterRow->id = $rowId;
        $counterRow->label = 'same';

        $this->assertSame($rowId, (new DBCounterRow())->updatePartialIgnoringRights($counterRow, 'label'));
        $this->assertSame(1, $this->countCounterRows());
    }

    public function testAPartialWriteOfTheCounterAddsTheIncomingValue(): void
    {
        $rowId = $this->insertCounterRow(['businessKey' => 'c', 'counter' => 100]);
        $counterRow = new CounterRow();
        $counterRow->id = $rowId;
        $counterRow->counter = 5;

        (new DBCounterRow())->updatePartialIgnoringRights($counterRow, 'counter');

        $this->assertSame(105, (int)$this->fetchRow('CounterRows', $rowId)['counter']);
    }

    public function testTheRightsScopedWriteOfTheCounterAddsTheIncomingValue(): void
    {
        $rowId = $this->insertCounterRow(['businessKey' => 'd', 'counter' => 100]);
        $counterRowModel = new DBCounterRowModel();
        $counterRowModel->id = $rowId;
        $counterRowModel->counter = 5;

        static::entityManager()->upsert($counterRowModel, DBCounterRow::createQueryBuilder(true));

        $this->assertSame(105, (int)$this->fetchRow('CounterRows', $rowId)['counter']);
    }

    public function testAKeepExistingActionLeavesTheColumnUnchangedInBothUpdatePaths(): void
    {
        $rowId = $this->insertCounterRow(['businessKey' => 'e', 'keptValue' => 11]);
        $counterRow = new CounterRow();
        $counterRow->id = $rowId;
        $counterRow->keptValue = 99;
        (new DBCounterRow())->updatePartialIgnoringRights($counterRow, 'keptValue');
        $this->assertSame(11, (int)$this->fetchRow('CounterRows', $rowId)['keptValue']);

        $counterRowModel = new DBCounterRowModel();
        $counterRowModel->id = $rowId;
        $counterRowModel->keptValue = 99;
        static::entityManager()->upsert($counterRowModel, DBCounterRow::createQueryBuilder(true));
        $this->assertSame(11, (int)$this->fetchRow('CounterRows', $rowId)['keptValue']);
    }

    public function testAPartialWriteOfATranslatableKeepsTheOtherLanguages(): void
    {
        $rowId = $this->insertTranslatedRow(['en' => 'Bread', 'fr' => 'Pain']);
        $previousLanguageCode = Translatable::getCurrentLanguageCode();
        Translatable::setCurrentLanguageCode('de');
        try {
            $translatedRow = new TranslatedRow();
            $translatedRow->id = $rowId;
            $translatedRow->name = 'Brot';
            (new \DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows\DBTranslatedRow())
                ->updatePartialIgnoringRights($translatedRow, 'name');
        } finally {
            Translatable::setCurrentLanguageCode($previousLanguageCode);
        }

        $storedNames = json_decode((string)$this->fetchRow('TranslatedRows', $rowId)['name'], true);
        // the key carries the writing style next to the language, e.g. de::FORMAL
        $germanNames = array_filter($storedNames, static fn(string $key): bool => str_starts_with($key, 'de'), ARRAY_FILTER_USE_KEY);
        $this->assertSame(['Brot'], array_values($germanNames));
        $this->assertSame('Bread', $storedNames['en'] ?? null);
        $this->assertSame('Pain', $storedNames['fr'] ?? null);
    }

    protected function insertTranslatedRow(array $names): int
    {
        static::connection()->insert('TranslatedRows', ['name' => json_encode($names)]);
        return (int)static::connection()->lastInsertId();
    }
}
