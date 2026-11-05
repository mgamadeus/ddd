<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\QueryOptions;

use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\Fixtures\Entities\TranslatedRows\TranslatedRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRowModel;
use DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows\DBTranslatedRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows\DBTranslatedRowModel;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * Bug 4 against MariaDB: what the database answers once a filter passed the content-based validation — typed
 * binds, wildcards kept as LIKE, a searchable Translatable compared and sorted on its search column, a plain one
 * sorted as before.
 */
class FilterAgainstDatabaseTest extends IntegrationTestCase
{
    /**
     * §6 step 5: the wildcard-on-int and TranslatedRow/JSON cases below used to be gated behind
     * {@see self::skipWildcardOnIntOnPostgres()} / {@see self::skipTranslatedRowCasesOnPostgres()} pending the
     * `TEXT_CAST`/`JSON_TEXT` dialect functions this step adds (now wired into
     * {@see \DDD\Domain\Base\Entities\QueryOptions\FiltersOptions} and
     * {@see \DDD\Domain\Base\Entities\QueryOptions\OrderByOptions}); the guards are kept as dead code (never
     * called) rather than deleted, so a future regression's fix is a one-line uncomment away from this same
     * documented root cause.
     */
    protected function setUp(): void
    {
        parent::setUp();
        static::insertRow('CounterRows', ['businessKey' => 'a', 'amount' => 4, 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => 'b', 'amount' => 14, 'isActive' => 0, 'occurredAt' => '2026-01-01 10:00:00']);
        static::insertRow('CounterRows', ['businessKey' => 'c', 'amount' => 20, 'isActive' => 1, 'occurredAt' => '2026-02-01 10:00:00']);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'Pizza']), 'title' => json_encode(['en' => 'Zebra pizza'])]);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'Pasta', 'de' => 'Nudeln']), 'title' => json_encode(['en' => 'Apple'])]);
        static::insertRow('TranslatedRows', ['name' => null, 'title' => json_encode(['en' => 'Mango'])]);
        // Real data from a production copy: a Translatable column can hold a JSON
        // ARRAY instead of an object (`[]`). `ddd_translatable_text()` used to call `json_each_text()` directly,
        // which requires an object and raised "cannot deconstruct an array as an object" on PostgreSQL for this
        // row — MariaDB's REGEXP_REPLACE/JSON_UNQUOTE template never minds the shape and renders the array's JSON
        // text verbatim. Kept at the end so every other test's positional destructuring of translatedRowIds()
        // (`[$pizzaRowId] = ...`, `[, $pastaId] = ...`, `[, , $unnamedId] = ...`) stays correct.
        static::insertRow('TranslatedRows', ['name' => json_encode([]), 'title' => json_encode(['en' => 'Kiwi'])]);
    }

    /**
     * A wildcard on a scalar kind keeps its LIKE (D8) by comparing the column, bare, against the pattern —
     * MariaDB coerces an int column to text for LIKE implicitly; PostgreSQL has no such implicit cast
     * ("operator does not exist: integer ~~ unknown") and needs the `TEXT_CAST` dialect function D8 describes,
     * which is query-side rendering (§6 step 5), not built by this (read-path identifiers + middlewares) task.
     */
    protected function skipWildcardOnIntOnPostgres(): void
    {
        if (static::currentDriver() === 'pdo_pgsql') {
            $this->markTestSkipped(
                'A wildcard on an int column renders bare `col LIKE ?` today, relying on MariaDB\'s implicit '
                . 'int->text cast; PostgreSQL needs the TEXT_CAST dialect function (D8), query-side rendering '
                . 'that is §6 step 5, not this task\'s identifiers/runtime-middleware scope.'
            );
        }
    }

    /**
     * Every TranslatedRow case compares or orders a `json`-typed PostgreSQL column with LIKE/ordering that needs
     * the JSON_TEXT/TEXT_CAST dialect functions (D8) — query-side rendering, §6 step 5, not this task's scope.
     * `lastInsertId()` additionally throws outright on PostgreSQL (behind the pooler, §4.6) and one case uses it.
     */
    protected function skipTranslatedRowCasesOnPostgres(): void
    {
        if (static::currentDriver() === 'pdo_pgsql') {
            $this->markTestSkipped(
                'TranslatedRow/JSON column comparisons need the JSON_TEXT/TEXT_CAST dialect functions (D8), '
                . 'query-side rendering that is §6 step 5, not this task\'s identifiers/runtime-middleware scope.'
            );
        }
    }

    /** @return string[] businessKey of the CounterRows the validated filter selects, ordered */
    protected function counterRowsMatching(string $filterQuery): array
    {
        $filtersOptions = FiltersOptions::fromString($filterQuery);
        $filtersOptions->validateAgainstDefinitions(FiltersDefinitions::getFiltersDefinitionsForReferenceClass(CounterRow::class));
        $queryBuilder = DBCounterRow::createQueryBuilder(true);
        $filtersOptions->applyFiltersToDoctrineQueryBuilder($queryBuilder);
        $queryBuilder->orderBy(DBCounterRowModel::MODEL_ALIAS . '.businessKey', 'ASC');
        return array_map(static fn(DBCounterRowModel $model): string => $model->businessKey, $queryBuilder->getQuery()->getResult());
    }

    /** @return int[] ids of the TranslatedRows the validated filter / orderBy selects, in result order */
    protected function translatedRowIdsMatching(?string $filterQuery, ?string $orderByQuery = null): array
    {
        $queryBuilder = DBTranslatedRow::createQueryBuilder(true);
        $filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(TranslatedRow::class);
        if ($filterQuery !== null) {
            $filtersOptions = FiltersOptions::fromString($filterQuery);
            $filtersOptions->validateAgainstDefinitions($filtersDefinitions);
            $filtersOptions->applyFiltersToDoctrineQueryBuilder($queryBuilder);
        }
        if ($orderByQuery !== null) {
            $orderByOptions = OrderByOptions::fromString($orderByQuery);
            $orderByOptions->applyOrderByToDoctrineQueryBuilder($queryBuilder, DBTranslatedRowModel::class);
        } else {
            $queryBuilder->orderBy(DBTranslatedRowModel::MODEL_ALIAS . '.id', 'ASC');
        }
        return array_map(static fn(DBTranslatedRowModel $model): int => $model->id, $queryBuilder->getQuery()->getResult());
    }

    protected function translatedRowIds(): array
    {
        $table = static::dialect()->quote('TranslatedRows');
        $id = static::dialect()->quote('id');
        return array_map('intval', static::connection()->fetchFirstColumn("SELECT $id FROM $table ORDER BY $id"));
    }

    public function testAQuotedIntIsComparedAsANumber(): void
    {
        $this->assertSame(['b'], $this->counterRowsMatching("amount eq '14'"));
        $this->assertSame(['b', 'c'], $this->counterRowsMatching("amount gt '9'"), "'9' as a string would sort after '14'");
        $this->assertSame(['a', 'c'], $this->counterRowsMatching('amount in [4,20]'));
    }

    public function testTrueMatchesTheTrueRows(): void
    {
        // MariaDB casts the string 'true' to 0: before the typed bind this matched the inactive row
        $this->assertSame(['a', 'c'], $this->counterRowsMatching("isActive eq 'true'"));
        $this->assertSame(['b'], $this->counterRowsMatching("isActive eq '0'"));
    }

    public function testNullComparisonsAnswerOnEveryKind(): void
    {
        $this->assertSame(['a'], $this->counterRowsMatching('occurredAt eq null'));
        $this->assertSame(['b', 'c'], $this->counterRowsMatching("occurredAt ne 'null'"));
        $this->assertSame([], $this->counterRowsMatching("amount eq 'NULL'"));
    }

    public function testAWildcardOnAnIntKeepsItsLike(): void
    {
        $this->assertSame(['a', 'b'], $this->counterRowsMatching("amount eq '*4*'"));
    }

    /**
     * D8/§4.8: a mandatory scope filter built via {@see FiltersOptions::createExpression()} +
     * {@see FiltersOptions::setFiltersDefinition()} (a trusted runtime value, never through the 400-validated
     * public filters= grammar — {@see FiltersOptions::validateAgainstDefinitions()} would reject `amount eq ''`
     * outright for not fitting the int column kind) can still bind a bare `''` against an int column — the real
     * shape is a "business-wide" scope, `locationId IS NULL OR locationId =
     * ''`, against legacy rows a pre-normalization write left holding `''` instead of NULL. MariaDB coerces the
     * string '' to 0 for an int comparison (`amount = ''` is `amount = 0`); PostgreSQL's pdo_pgsql driver instead
     * rejects the bind ("invalid input syntax for type bigint"). Only the zero-amount row must match, on both
     * engines.
     */
    public function testAnEmptyStringAgainstAnIntColumnMatchesZeroLikeMariaDbsCoercion(): void
    {
        static::insertRow('CounterRows', ['businessKey' => 'zero', 'amount' => 0, 'isActive' => 1, 'occurredAt' => null]);

        $queryBuilder = DBCounterRow::createQueryBuilder(true);
        $filtersOptions = FiltersOptions::createExpression('amount', FiltersOptions::OPERATOR_EQUAL, '');
        $filtersOptions->setFiltersDefinition(
            FiltersDefinitions::getFiltersDefinitionsForReferenceClass(CounterRow::class)
                ->getFilterDefinitionForPropertyName('amount')
        );
        $filtersOptions->applyFiltersToDoctrineQueryBuilder($queryBuilder);
        $queryBuilder->orderBy(DBCounterRowModel::MODEL_ALIAS . '.businessKey', 'ASC');

        $businessKeys = array_map(
            static fn(DBCounterRowModel $model): string => $model->businessKey,
            $queryBuilder->getQuery()->getResult()
        );
        $this->assertSame(['zero'], $businessKeys);
    }

    public function testADateOnADateTimeColumnComparesFromMidnight(): void
    {
        $this->assertSame(['c'], $this->counterRowsMatching("occurredAt ge '2026-01-15'"));
    }

    public function testAWildcardOnAPlainTranslatableKeepsItsLikeOnTheJsonText(): void
    {
        [$pizzaRowId] = $this->translatedRowIds();
        $this->assertSame([$pizzaRowId], $this->translatedRowIdsMatching("title eq '*piz*'"));
    }

    public function testAnExactComparisonOnAPlainTranslatableIsA400(): void
    {
        $this->expectException(BadRequestException::class);
        $this->translatedRowIdsMatching("title eq 'Pizza'");
    }

    public function testAPlainTranslatableSortsAsBefore(): void
    {
        [$zebraPizzaId, $appleId, $mangoId, $kiwiId] = $this->translatedRowIds();
        // the JSON text order: {"en": "Apple"} < {"en": "Kiwi"} < {"en": "Mango"} < {"en": "Zebra pizza"}
        $this->assertSame([$appleId, $kiwiId, $mangoId, $zebraPizzaId], $this->translatedRowIdsMatching(null, 'title asc'));
    }

    public function testAnExactComparisonOnASearchableTranslatableMatchesASingleLanguageRow(): void
    {
        [$pizzaId] = $this->translatedRowIds();
        // against the JSON source column 'Pizza' never matched
        $this->assertSame([$pizzaId], $this->translatedRowIdsMatching("name eq 'Pizza'"));
    }

    public function testAWildcardOnASearchableTranslatableMatchesOnItsSearchColumn(): void
    {
        [, $pastaId] = $this->translatedRowIds();
        $this->assertSame([$pastaId], $this->translatedRowIdsMatching("name eq '*nudel*'"));
    }

    public function testANullComparisonOnASearchableTranslatableUsesTheSourceColumn(): void
    {
        [, , $unnamedId] = $this->translatedRowIds();
        // the search column holds '' for a NULL source
        $this->assertSame([$unnamedId], $this->translatedRowIdsMatching("name eq 'NULL'"));
    }

    public function testASearchableTranslatableSortsByItsSearchText(): void
    {
        // `lastInsertId()` throws outright on PostgreSQL (behind the pooler, Arch §4.6) — this fixture used to be
        // gated behind that on top of the JSON_TEXT/TEXT_CAST need; it now only needs its two rows isolated and
        // identified without that call, so it uses the harness's own `insertRow()` (which already quotes
        // identifiers per dialect, D1) and reads ids back via `translatedRowIds()` instead.
        static::connection()->executeStatement('DELETE FROM ' . static::dialect()->quote('TranslatedRows'));
        // JSON text order: {"en": "Cerise"} < {"fr": "Banane"}; search text order: Banane < Cerise
        static::insertRow('TranslatedRows', ['name' => json_encode(['fr' => 'Banane'])]);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'Cerise'])]);
        [$bananeId, $ceriseId] = $this->translatedRowIds();

        $this->assertSame([$bananeId, $ceriseId], $this->translatedRowIdsMatching(null, 'name asc'));
    }

    /**
     * The JSON-array-source row from {@see self::setUp()}: on PostgreSQL this used to make
     * `ddd_translatable_text()` throw outright (`cannot deconstruct an array as an object`) the moment its search
     * column was read — ordering by `name` reads it for every row. It must survive, render an empty search text
     * (nothing language-keyed to extract from an array; same "no match" outcome a NULL source gets, D4's search
     * column not being the comparison this asserts), and never match a NULL-source filter (its JSON column holds
     * `[]`, not SQL NULL, on both engines).
     */
    public function testASearchableTranslatableWithAJsonArraySourceRendersAnEmptySearchTextInsteadOfErroring(): void
    {
        [, , $unnamedId, $arrayRowId] = $this->translatedRowIds();
        $orderedIds = $this->translatedRowIdsMatching(null, 'name asc');
        $this->assertContains($arrayRowId, $orderedIds, 'the array-source row must not be dropped or error out');
        $this->assertSame([$unnamedId], $this->translatedRowIdsMatching("name eq 'NULL'"), 'a JSON array is not a SQL NULL');
    }

    public function testLikeMetacharactersInAWildcardValueAreLiteral(): void
    {
        static::insertRow('CounterRows', ['businessKey' => 'a_c', 'amount' => 1, 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => 'abc', 'amount' => 1, 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => '50%', 'amount' => 1, 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => 'tail\\', 'amount' => 1, 'isActive' => 1, 'occurredAt' => null]);

        // `_` and `%` match themselves; a value ending in `\` is no 22025 on PostgreSQL
        $this->assertSame(['a_c'], $this->counterRowsMatching("businessKey eq 'a_*'"));
        $this->assertSame(['50%'], $this->counterRowsMatching("businessKey eq '*0%'"));
        $this->assertSame(['tail\\'], $this->counterRowsMatching("businessKey eq '*tail\\\\'"));
    }

    public function testATranslatableWithoutValueSortsFirstAscendingAndLastDescending(): void
    {
        static::insertRow('TranslatedRows', ['name' => null, 'title' => null]);
        $rowIds = $this->translatedRowIds();
        $untitledId = end($rowIds);

        $ascending = $this->translatedRowIdsMatching(null, 'title asc');
        $descending = $this->translatedRowIdsMatching(null, 'title desc');

        // MariaDB sorts NULL as the smallest value: first ascending, last descending — PostgreSQL alike
        $this->assertSame($untitledId, $ascending[0]);
        $this->assertSame($untitledId, end($descending));
    }

    public function testANulByteInATextValueIsStoredWithoutItOnPostgres(): void
    {
        $counterRow = new CounterRow();
        $counterRow->businessKey = "nul\0byte";
        $counterRow->amount = 1;
        $counterRow->isActive = true;
        $counterRow->displayOrder = 0;

        $counterRow->update();

        $stored = (string)static::connection()->fetchOne(
            'SELECT ' . static::dialect()->quote('businessKey') . ' FROM ' . static::dialect()->quote('CounterRows')
            . ' WHERE ' . static::dialect()->quote('id') . ' = ?',
            [$counterRow->id]
        );
        // PostgreSQL rejects NUL in text (22021); MariaDB keeps the byte
        $this->assertSame(static::currentDriver() === 'pdo_pgsql' ? 'nulbyte' : "nul\0byte", $stored);
    }

    public function testAFulltextTermIsALiteralPrefixWithoutLikeWildcards(): void
    {
        [, $pastaId] = $this->translatedRowIds();

        $this->assertSame([$pastaId], $this->translatedRowIdsMatching("name ft 'Pasta'"));
        // `_` was a LIKE wildcard in PostgreSQL's NAME mode ("Pas_a" matched "Pasta"); MariaDB's MATCH never did
        $this->assertSame([], $this->translatedRowIdsMatching("name ft 'Pas_a'"));
    }
}
