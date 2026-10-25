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
        [$zebraPizzaId, $appleId, $mangoId] = $this->translatedRowIds();
        // the JSON text order: {"en": "Apple"} < {"en": "Mango"} < {"en": "Zebra pizza"}
        $this->assertSame([$appleId, $mangoId, $zebraPizzaId], $this->translatedRowIdsMatching(null, 'title asc'));
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
}
