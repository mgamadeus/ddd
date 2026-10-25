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
     * Only this class's TranslatedRow/JSON cases are gated — see the per-test {@see self::skipTranslatedRowCasesOnPostgres()}
     * and {@see self::skipWildcardOnIntOnPostgres()} calls below. The CounterRow-only cases (plain int/bool/null
     * comparisons, no wildcard) are plain portable DQL (`QueryBuilder::expr()`) against quoted scalar columns and
     * need neither: they run on both engines (§6 step 4/5 item 5, "QueryOptions basic filters").
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
        return array_map('intval', static::connection()->fetchFirstColumn('SELECT id FROM TranslatedRows ORDER BY id'));
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
        $this->skipWildcardOnIntOnPostgres();
        $this->assertSame(['a', 'b'], $this->counterRowsMatching("amount eq '*4*'"));
    }

    public function testADateOnADateTimeColumnComparesFromMidnight(): void
    {
        $this->assertSame(['c'], $this->counterRowsMatching("occurredAt ge '2026-01-15'"));
    }

    public function testAWildcardOnAPlainTranslatableKeepsItsLikeOnTheJsonText(): void
    {
        $this->skipTranslatedRowCasesOnPostgres();
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
        $this->skipTranslatedRowCasesOnPostgres();
        [$zebraPizzaId, $appleId, $mangoId] = $this->translatedRowIds();
        // the JSON text order: {"en": "Apple"} < {"en": "Mango"} < {"en": "Zebra pizza"}
        $this->assertSame([$appleId, $mangoId, $zebraPizzaId], $this->translatedRowIdsMatching(null, 'title asc'));
    }

    public function testAnExactComparisonOnASearchableTranslatableMatchesASingleLanguageRow(): void
    {
        $this->skipTranslatedRowCasesOnPostgres();
        [$pizzaId] = $this->translatedRowIds();
        // against the JSON source column 'Pizza' never matched
        $this->assertSame([$pizzaId], $this->translatedRowIdsMatching("name eq 'Pizza'"));
    }

    public function testAWildcardOnASearchableTranslatableMatchesOnItsSearchColumn(): void
    {
        $this->skipTranslatedRowCasesOnPostgres();
        [, $pastaId] = $this->translatedRowIds();
        $this->assertSame([$pastaId], $this->translatedRowIdsMatching("name eq '*nudel*'"));
    }

    public function testANullComparisonOnASearchableTranslatableUsesTheSourceColumn(): void
    {
        $this->skipTranslatedRowCasesOnPostgres();
        [, , $unnamedId] = $this->translatedRowIds();
        // the search column holds '' for a NULL source
        $this->assertSame([$unnamedId], $this->translatedRowIdsMatching("name eq 'NULL'"));
    }

    public function testASearchableTranslatableSortsByItsSearchText(): void
    {
        $this->skipTranslatedRowCasesOnPostgres();
        $connection = static::connection();
        $connection->executeStatement('DELETE FROM TranslatedRows');
        // JSON text order: {"en": "Cerise"} < {"fr": "Banane"}; search text order: Banane < Cerise
        $connection->insert('TranslatedRows', ['name' => json_encode(['fr' => 'Banane'])]);
        $bananeId = (int)$connection->lastInsertId();
        $connection->insert('TranslatedRows', ['name' => json_encode(['en' => 'Cerise'])]);
        $ceriseId = (int)$connection->lastInsertId();

        $this->assertSame([$bananeId, $ceriseId], $this->translatedRowIdsMatching(null, 'name asc'));
    }
}
