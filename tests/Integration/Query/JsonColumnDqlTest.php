<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRowModel;
use DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows\DBTranslatedRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows\DBTranslatedRowModel;
use DDD\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;

/**
 * D8 (dual-dialect architecture plan §4.8, {@see \DDD\Domain\Base\Repo\DB\Dialect\Query\JsonColumnAwareTrait}): DQL
 * that reaches a `json` column directly through a RAW `DoctrineQueryBuilder` call — `orderBy()`, `andWhere()`,
 * `distinct()`, a hand-written `GROUP BY` — never goes through
 * {@see \DDD\Domain\Base\Entities\QueryOptions\OrderByOptions} / {@see \DDD\Domain\Base\Entities\QueryOptions\FiltersOptions}'s
 * own `JSON_TEXT`/400 handling, so it needs the fix at the walker level. `TranslatedRow.title` is a PLAIN
 * Translatable (no `fullTextIndex`, so no virtual search column to route through) — exactly the shape behind the
 * real Dineful PostgreSQL failures in `tmp/runs/pgspike/pg-run5-errors.txt`: "could not identify an ordering
 * operator for type json" (×9), "operator does not exist: json = unknown", and "could not identify an equality
 * operator for type json" (×5, `SELECT DISTINCT` hydrating an entity with `json` columns —
 * {@see \DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder::distinct()}'s own to-many-LEFT-JOIN-dedup use case).
 */
class JsonColumnDqlTest extends IntegrationTestCase
{
    /**
     * Real failure (×9 in the run5 log): a raw `orderBy()` on a Translatable column. Same text order on both
     * engines — MariaDB sorts the stored JSON text's bytes (`utf8mb4_bin` LONGTEXT); {@see PostgresSqlWalker}
     * casts to `text` under the `"C"` collation to match.
     *
     * @group query
     */
    public function testRawOrderByOnAPlainTranslatableColumnMatchesOnBothEngines(): void
    {
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'n1']), 'title' => json_encode(['en' => 'Charlie'])]);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'n2']), 'title' => json_encode(['en' => 'Alpha'])]);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'n3']), 'title' => json_encode(['en' => 'Bravo'])]);

        $queryBuilder = DBTranslatedRow::createQueryBuilder(true);
        $queryBuilder->orderBy(DBTranslatedRowModel::MODEL_ALIAS . '.title', 'ASC');
        $titles = array_map(
            static fn(DBTranslatedRowModel $model): string => $model->title['en'],
            $queryBuilder->getQuery()->getResult()
        );

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $titles, 'raw orderBy() on a json column sorts identically on both engines');
    }

    /**
     * Real failure: "operator does not exist: json = unknown" — a raw equality filter on a json column — plus the
     * same construct's `LIKE` sibling.
     *
     * @group query
     */
    public function testRawEqualityAndLikeFiltersOnAPlainTranslatableColumn(): void
    {
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'n1']), 'title' => json_encode(['en' => 'Alpha'])]);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'n2']), 'title' => json_encode(['en' => 'Bravo'])]);

        $alias = DBTranslatedRowModel::MODEL_ALIAS;

        $equalityQueryBuilder = DBTranslatedRow::createQueryBuilder(true);
        $equalityQueryBuilder->andWhere("$alias.title = :title")->setParameter('title', json_encode(['en' => 'Alpha']));
        $equalityResult = $equalityQueryBuilder->getQuery()->getResult();
        $this->assertCount(1, $equalityResult, 'a raw = comparison on a json column matches on both engines');
        $this->assertSame('Alpha', $equalityResult[0]->title['en']);

        $likeQueryBuilder = DBTranslatedRow::createQueryBuilder(true);
        $likeQueryBuilder->andWhere("$alias.title LIKE :pattern")->setParameter('pattern', '%Bravo%');
        $likeResult = $likeQueryBuilder->getQuery()->getResult();
        $this->assertCount(1, $likeResult, 'a raw LIKE on a json column matches on both engines');
        $this->assertSame('Bravo', $likeResult[0]->title['en']);
    }

    /**
     * Real failure (×5 in the run5 log): `SELECT DISTINCT` hydrating an entity with json columns. No real
     * duplicate rows are needed to reproduce it: PostgreSQL demands a hash/equality operator on EVERY selected
     * column the moment `isDistinct` is true, json or not.
     *
     * @group query
     */
    public function testDistinctHydrationOverAnEntityWithJsonColumns(): void
    {
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'n1']), 'title' => json_encode(['en' => 'Alpha'])]);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'n2']), 'title' => json_encode(['en' => 'Bravo'])]);

        $queryBuilder = DBTranslatedRow::createQueryBuilder(true);
        $queryBuilder->distinct();
        $result = $queryBuilder->getQuery()->getResult();

        $this->assertCount(2, $result, 'SELECT DISTINCT over an entity with json columns executes and returns both rows on both engines');
    }

    /**
     * `GROUP BY` a json column, with the SAME column ALSO in the select list — exercises `walkGroupByItem()` AND
     * `castJsonSelectColumnsForDistinctOrGroupBy()` together.
     *
     * @group query
     */
    public function testGroupByAPlainTranslatableColumnCountsGroupsOnBothEngines(): void
    {
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'n1']), 'title' => json_encode(['en' => 'Alpha'])]);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'n2']), 'title' => json_encode(['en' => 'Alpha'])]);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'n3']), 'title' => json_encode(['en' => 'Bravo'])]);

        $alias = DBTranslatedRowModel::MODEL_ALIAS;
        $query = DBTranslatedRow::createQueryBuilder(true)->getEntityManager()->createQuery(
            "SELECT $alias.title AS title, COUNT($alias.id) AS cnt FROM " . DBTranslatedRowModel::class
            . " $alias GROUP BY $alias.title ORDER BY cnt DESC"
        );
        $rows = $query->getResult(Query::HYDRATE_SCALAR);

        $this->assertCount(2, $rows, 'two distinct json title groups on both engines');
        $this->assertSame(2, (int)$rows[0]['cnt'], 'Alpha appears twice');
        $this->assertSame(1, (int)$rows[1]['cnt'], 'Bravo appears once');
    }

    /**
     * D2 + D8 together: pagination with joins + a limit (the ROW_NUMBER id page, {@see RowNumberPagePagination} /
     * {@see PostgresLimitSubqueryOutputWalker}) ordered by a json column from the JOINED table — same page, same
     * order, on both engines.
     *
     * @group query
     */
    public function testPaginationWithJoinsAndLimitOrderedByAJsonColumnMatchesOnBothEngines(): void
    {
        static::insertRow('CounterRows', ['businessKey' => 'a', 'amount' => 1, 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => 'b', 'amount' => 2, 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => 'c', 'amount' => 3, 'isActive' => 1, 'occurredAt' => null]);

        $idColumn = static::quoteIdentifier('id');
        $businessKeyColumn = static::quoteIdentifier('businessKey');
        $idsByKey = [];
        foreach (['a', 'b', 'c'] as $key) {
            $idsByKey[$key] = (int)static::connection()->fetchOne(
                'SELECT ' . $idColumn . ' FROM ' . static::quoteIdentifier('CounterRows') . ' WHERE ' . $businessKeyColumn . ' = ?',
                [$key]
            );
        }
        // title order: Alpha (b) < Bravo (c) < Charlie (a) — deliberately NOT the amount/businessKey order, so an
        // unordered or amount-ordered page would fail this assertion.
        static::insertRow('TranslatedRows', ['id' => $idsByKey['a'], 'name' => json_encode(['en' => 'n']), 'title' => json_encode(['en' => 'Charlie'])]);
        static::insertRow('TranslatedRows', ['id' => $idsByKey['b'], 'name' => json_encode(['en' => 'n']), 'title' => json_encode(['en' => 'Alpha'])]);
        static::insertRow('TranslatedRows', ['id' => $idsByKey['c'], 'name' => json_encode(['en' => 'n']), 'title' => json_encode(['en' => 'Bravo'])]);

        $queryBuilder = DBCounterRow::createQueryBuilder(true);
        $queryBuilder->leftJoin(
            DBTranslatedRowModel::class,
            't',
            Join::WITH,
            't.id = ' . DBCounterRowModel::MODEL_ALIAS . '.id'
        );
        $queryBuilder->orderBy('t.title', 'ASC');
        $queryBuilder->setFirstResult(0)->setMaxResults(2);

        $result = $queryBuilder->applyDistinctSubqueryIfNeededAndGetResult();

        $businessKeys = array_map(static fn(DBCounterRowModel $model): string => $model->businessKey, $result);
        $this->assertSame(['b', 'c'], $businessKeys, 'first page of 2, ordered by the joined json column, on both engines');
    }
}
