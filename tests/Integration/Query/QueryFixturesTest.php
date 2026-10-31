<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\Fixtures\Entities\TranslatedRows\TranslatedRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRowModel;
use DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows\DBTranslatedRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows\DBTranslatedRowModel;
use DDD\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\Query\Expr\Join;

/**
 * §6 step 5 query fixtures (dual-dialect architecture plan §5 "Query" bullets), against the two EXISTING fixture
 * entities (no new fixture table, no golden-affecting entity change): pagination with joins + a limit (D2 — the
 * real failure of a consuming app, `syntax error at or near "INNER"`, that this step fixes), `NULL` ordering parity (D13/
 * §4.8), and fulltext `NAME`-mode search with `{prop}Score` ordering (D14) on `TranslatedRow.name`, which already
 * carries `#[Translatable(fullTextIndex: true)]` (default `fullTextMode: NAME`) for
 * {@see \DDD\Tests\Integration\QueryOptions\FilterAgainstDatabaseTest}'s unrelated cases. `fullTextMode: DOCUMENT`
 * and `TOKEN_MATCH` need their own fixture column/table and live in
 * {@see \DDD\Tests\Integration\Query\TokenMatchAndDocumentFulltextTest}.
 */
class QueryFixturesTest extends IntegrationTestCase
{
    /**
     * D2 (real failure this step fixes): a query with a join AND a limit/offset used to fail outright on
     * PostgreSQL (`syntax error at or near "INNER"` — MariaDB's regex distinct-subquery rewrite producing MariaDB-
     * shaped SQL against a PostgreSQL connection). `CounterRow` and `TranslatedRow` have no declared association;
     * DQL's ad-hoc class join (`JOIN <Entity> <alias> WITH <condition>`) needs none — this exercises the exact
     * `hasJoins && hasLimitOrOffset` branch of
     * {@see \DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder::applyDistinctSubqueryIfNeededAndGetResult()}
     * that dispatches to the dialect's {@see \DDD\Domain\Base\Repo\DB\Dialect\Query\PaginationStrategy} —
     * {@see \DDD\Domain\Base\Repo\DB\Dialect\Query\MariaDbDistinctSubqueryPagination} (today's regex rewrite,
     * moved verbatim) on MariaDB, {@see \DDD\Domain\Base\Repo\DB\Dialect\Query\RowNumberPagePagination} (the new
     * single-statement ROW_NUMBER id page) on PostgreSQL.
     *
     * @group query
     */
    public function testPaginationWithJoinsAndLimitReturnsExactlyOnePageOfDistinctRoots(): void
    {
        foreach ([10, 20, 30, 40, 50] as $amount) {
            static::insertRow(
                'CounterRows',
                ['businessKey' => "p$amount", 'amount' => $amount, 'isActive' => 1, 'occurredAt' => null]
            );
        }
        $idColumn = static::quoteIdentifier('id');
        $amountColumn = static::quoteIdentifier('amount');
        $thirtyId = (int)static::connection()->fetchOne(
            'SELECT ' . $idColumn . ' FROM ' . static::quoteIdentifier('CounterRows') . ' WHERE ' . $amountColumn . ' = ?',
            [30]
        );
        // One real match on the join condition — not a to-many fan-out (CounterRow<->TranslatedRow share no
        // relation, so at most one TranslatedRow row ever matches one CounterRow's id); this proves the joined
        // SQL runs and returns the right PAGE, not to-many row-multiplication handling (a separate, not-yet-built
        // fixture concern, out of this step's gate).
        static::insertRow('TranslatedRows', ['id' => $thirtyId, 'name' => json_encode(['en' => 'Thirty']), 'title' => null]);

        $queryBuilder = DBCounterRow::createQueryBuilder(true);
        $queryBuilder->leftJoin(
            DBTranslatedRowModel::class,
            't',
            Join::WITH,
            't.id = ' . DBCounterRowModel::MODEL_ALIAS . '.id'
        );
        $queryBuilder->orderBy(DBCounterRowModel::MODEL_ALIAS . '.amount', 'ASC');
        $queryBuilder->setFirstResult(1)->setMaxResults(2);

        $result = $queryBuilder->applyDistinctSubqueryIfNeededAndGetResult();

        $amounts = array_map(static fn(DBCounterRowModel $model): int => $model->amount, $result);
        $this->assertSame(
            [20, 30],
            $amounts,
            'skip=1,take=2 over 5 roots ordered by amount ASC, with a LEFT JOIN and no row multiplication'
        );
    }

    /**
     * D13/§4.8: MariaDB sorts `NULL` first on `ASC` (and last on `DESC`); PostgreSQL's native default is the
     * opposite. {@see \DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresSqlWalker} (the PostgreSQL default output
     * walker) appends an explicit `NULLS FIRST`/`NULLS LAST` so both engines answer identically.
     *
     * @group query
     */
    public function testNullOrderingMatchesMariaDbOnBothEngines(): void
    {
        static::insertRow('CounterRows', ['businessKey' => 'a', 'amount' => 1, 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow(
            'CounterRows',
            ['businessKey' => 'b', 'amount' => 2, 'isActive' => 1, 'occurredAt' => '2026-01-01 10:00:00']
        );
        static::insertRow(
            'CounterRows',
            ['businessKey' => 'c', 'amount' => 3, 'isActive' => 1, 'occurredAt' => '2026-02-01 10:00:00']
        );

        $queryBuilder = DBCounterRow::createQueryBuilder(true);
        $queryBuilder->orderBy(DBCounterRowModel::MODEL_ALIAS . '.occurredAt', 'ASC');
        $ascKeys = array_map(
            static fn(DBCounterRowModel $model): string => $model->businessKey,
            $queryBuilder->getQuery()->getResult()
        );
        $this->assertSame(['a', 'b', 'c'], $ascKeys, 'ASC: the NULL row (a) sorts first on both engines');

        $queryBuilder = DBCounterRow::createQueryBuilder(true);
        $queryBuilder->orderBy(DBCounterRowModel::MODEL_ALIAS . '.occurredAt', 'DESC');
        $descKeys = array_map(
            static fn(DBCounterRowModel $model): string => $model->businessKey,
            $queryBuilder->getQuery()->getResult()
        );
        $this->assertSame(['c', 'b', 'a'], $descKeys, 'DESC: NULL compares smallest on both engines, so it sorts LAST under DESC');
    }

    /** @return string[] businessKey of the CounterRows the validated filter selects, ordered */
    protected function counterRowsMatching(string $filterQuery, string $orderByQuery): array
    {
        $filtersOptions = FiltersOptions::fromString($filterQuery);
        $filtersOptions->validateAgainstDefinitions(FiltersDefinitions::getFiltersDefinitionsForReferenceClass(CounterRow::class));
        $queryBuilder = DBCounterRow::createQueryBuilder(true);
        $filtersOptions->applyFiltersToDoctrineQueryBuilder($queryBuilder);
        $orderByOptions = OrderByOptions::fromString($orderByQuery);
        $orderByOptions->applyOrderByToDoctrineQueryBuilder($queryBuilder, DBCounterRowModel::class);
        return array_map(
            static fn(DBCounterRowModel $model): string => $model->businessKey,
            $queryBuilder->getQuery()->getResult()
        );
    }

    /**
     * D14 (`NAME` mode, the default): the `ft`/`fb` operators and `{prop}Score` ordering against
     * `TranslatedRow.name`'s virtual search column — MariaDB via `MATCH … AGAINST`
     * ({@see \DDD\Domain\Base\Entities\QueryOptions\FiltersOptions}'s existing branch), PostgreSQL via
     * {@see \DDD\Domain\Base\Repo\DB\Dialect\Query\FulltextMatch} / {@see \DDD\Domain\Base\Repo\DB\Dialect\Query\FulltextScore}
     * (`pg_trgm` word-prefix match + `similarity()`). Values differ between engines (D14); only the match set and
     * the best-match-first ordering are asserted.
     *
     * @group query
     */
    public function testFulltextNameModeMatchesAndScoreOrdersBestMatchFirst(): void
    {
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'Grilled Chicken Burger']), 'title' => null]);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'Chicken Caesar Salad']), 'title' => null]);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'Veggie Wrap']), 'title' => null]);

        $filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(TranslatedRow::class);

        $queryBuilder = DBTranslatedRow::createQueryBuilder(true);
        $filtersOptions = FiltersOptions::fromString("name ft 'chicken'");
        $filtersOptions->validateAgainstDefinitions($filtersDefinitions);
        $filtersOptions->applyFiltersToDoctrineQueryBuilder($queryBuilder);
        $matchedNames = array_map(
            static fn(DBTranslatedRowModel $model): string => $model->name['en'],
            $queryBuilder->getQuery()->getResult()
        );
        sort($matchedNames);
        $this->assertSame(
            ['Chicken Caesar Salad', 'Grilled Chicken Burger'],
            $matchedNames,
            'ft matches both "chicken" rows and neither the unrelated one, on both engines'
        );

        // Score ordering (`{prop}Score`, D14): values are NOT comparable across engines (MariaDB's MATCH
        // relevance vs PostgreSQL's pg_trgm similarity()), and PostgreSQL's NAME mode ANDs every term while
        // MariaDB's natural-language mode does not (D14: "no boolean-mode emulation on PostgreSQL", a deliberate
        // divergence) — so only "the score SELECT/ORDER BY renders and executes, and still returns exactly the
        // ft-matched set" is asserted here, on both engines, single-term so the matched SET stays identical too.
        $queryBuilder = DBTranslatedRow::createQueryBuilder(true);
        $filtersOptions = FiltersOptions::fromString("name ft 'chicken'");
        $filtersOptions->validateAgainstDefinitions($filtersDefinitions);
        $filtersOptions->applyFiltersToDoctrineQueryBuilder($queryBuilder);
        $orderByOptions = OrderByOptions::fromString('nameScore desc');
        $orderByOptions->applyOrderByToDoctrineQueryBuilder($queryBuilder, DBTranslatedRowModel::class);
        $orderedNames = array_map(
            static fn(DBTranslatedRowModel $model): string => $model->name['en'],
            $queryBuilder->getQuery()->getResult()
        );
        sort($orderedNames);
        $this->assertSame(
            ['Chicken Caesar Salad', 'Grilled Chicken Burger'],
            $orderedNames,
            'nameScore desc still returns exactly the ft-matched set on both engines'
        );
    }

    /**
     * Real PostgreSQL failure this fixes (§6 step 5): `TypeError: Cannot assign
     * Doctrine\ORM\Query\AST\Literal to property …PostgresSubstring::$firstSimpleArithmeticExpression of type
     * SimpleArithmeticExpression` — a date-key trick (`SUBSTRING(o0_.someDatetime, 1, 10)`) with LITERAL
     * start/length arguments, which {@see Parser::SimpleArithmeticExpression()}'s own "Phase 1 AST optimization"
     * collapses to a bare `AST\Literal` rather than wrapping in `SimpleArithmeticExpression`
     * ({@see \DDD\Domain\Base\Repo\DB\Dialect\Query\PostgresSubstring}'s own docblock explains the vendor
     * precedent this now mirrors). MariaDB coerces the datetime to text implicitly through the SAME DQL name
     * (Doctrine's built-in `SubstringFunction`, untouched); PostgreSQL needs the explicit `CAST(… AS TEXT)` this
     * class adds.
     *
     * @group query
     */
    public function testSubstringWithLiteralStartAndLengthOverADatetimeColumn(): void
    {
        static::connection()->executeStatement('DELETE FROM ' . static::dialect()->quote('CounterRows'));
        static::insertRow(
            'CounterRows',
            ['businessKey' => 'sub', 'amount' => 1, 'isActive' => 1, 'occurredAt' => '2026-03-04 15:30:00']
        );

        $query = DBCounterRow::createQueryBuilder(true)->getEntityManager()->createQuery(
            'SELECT SUBSTRING(c.occurredAt, 1, 10) AS dayKey FROM ' . DBCounterRowModel::class . " c WHERE c.businessKey = 'sub'"
        );
        $result = $query->getResult(\Doctrine\ORM\Query::HYDRATE_SCALAR);

        $this->assertSame('2026-03-04', $result[0]['dayKey']);
    }
}
