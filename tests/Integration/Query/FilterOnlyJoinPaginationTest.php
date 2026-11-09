<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerParent;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerParentModel;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerChildModel;
use DDD\Tests\Integration\IntegrationTestCase;
use DDD\Tests\Integration\Query\Support\MainSqlRenderingRowNumberPagePagination;
use Doctrine\ORM\Query\Expr\Join;

/**
 * A paginated query whose to-many join only FILTERS (the joined alias is not selected) keeps its WHERE in the query
 * that loads the page's roots. Only a fetch-joined collection needs its roots loaded without the WHERE, so that the
 * collection comes back complete ({@see ExpandFilterSemanticsTest}). Without one, a dropped WHERE joins every child
 * of every root on the page: the statement reads every child row instead of the matching ones, and an ORDER BY or
 * GROUP BY over the joined rows sees children the filter excluded, which re-ranks the page.
 *
 * On PostgreSQL the roots come back in the right order either way: the main query is sorted by the id page's rank
 * first. There, keeping the WHERE only saves the extra child rows.
 */
class FilterOnlyJoinPaginationTest extends IntegrationTestCase
{
    use TieBreakerFixtureTablesTrait;

    protected const string FILTER_ONLY_ALIAS = 'filterOnlyChildren';

    /** @param string[] $labels the parent's child labels, inserted id-ascending */
    protected function insertParentWithChildren(string $businessKey, array $labels): void
    {
        static::insertRow('TieBreakerParents', ['businessKey' => $businessKey]);
        $parentId = $this->findParentIdByBusinessKey($businessKey);
        foreach ($labels as $position => $label) {
            static::insertRow(
                'TieBreakerChildren',
                ['id' => $parentId * 1000 + $position + 1, 'tieBreakerParentId' => $parentId, 'label' => $label]
            );
        }
    }

    /** @return string[] the business keys of the loaded parent models, in result order */
    protected function businessKeysInResultOrder(iterable $parentModels): array
    {
        $businessKeys = [];
        foreach ($parentModels as $parentModel) {
            $businessKeys[] = $parentModel->businessKey;
        }
        return $businessKeys;
    }

    public function testAPaginatedFilterOnAJoinThatIsNotSelectedOrdersTheRootsByTheMatchingChildrenOnly(): void
    {
        // "late-match" has a child that sorts before every match but does not match the filter.
        $this->insertParentWithChildren('late-match', ['apple', 'pear']);
        $this->insertParentWithChildren('early-match', ['peach']);
        $this->insertParentWithChildren('no-match', ['apricot']);
        $parentAlias = DBTieBreakerParentModel::MODEL_ALIAS;
        $childrenAlias = self::FILTER_ONLY_ALIAS;

        $queryBuilder = DBTieBreakerParent::createQueryBuilder(true);
        $queryBuilder->leftJoin("$parentAlias.tieBreakerChildren", $childrenAlias)
            ->andWhere("$childrenAlias.label LIKE :labelPrefix")
            ->setParameter('labelPrefix', 'p%')
            ->orderBy("$childrenAlias.label", 'ASC')
            ->setMaxResults(2);
        $this->assertFalse($queryBuilder->hasFetchJoinedCollection());

        $this->assertSame(
            ['early-match', 'late-match'],
            $this->businessKeysInResultOrder($queryBuilder->applyDistinctSubqueryIfNeededAndGetResult(true))
        );
    }

    public function testAPaginatedGroupedQueryRanksTheRootsByTheirMatchingChildrenOnly(): void
    {
        // "one-match" has the most children, but only one of them matches the filter.
        $this->insertParentWithChildren('one-match', ['pizza', 'cola', 'water', 'juice']);
        $this->insertParentWithChildren('two-matches', ['pizza', 'pizza']);
        $this->insertParentWithChildren('no-match', ['cola']);
        $parentAlias = DBTieBreakerParentModel::MODEL_ALIAS;
        $childrenAlias = self::FILTER_ONLY_ALIAS;

        $queryBuilder = DBTieBreakerParent::createQueryBuilder(true);
        $queryBuilder->leftJoin("$parentAlias.tieBreakerChildren", $childrenAlias)
            ->andWhere("$childrenAlias.label = :label")
            ->setParameter('label', 'pizza')
            ->groupBy("$parentAlias.id")
            ->addGroupBy("$parentAlias.businessKey")
            ->orderBy("COUNT($childrenAlias.id)", 'DESC')
            ->setMaxResults(2);

        $this->assertSame(
            ['two-matches', 'one-match'],
            $this->businessKeysInResultOrder($queryBuilder->applyDistinctSubqueryIfNeededAndGetResult(true))
        );
    }

    public function testAPaginatedGroupedQueryWithoutOrderByRuns(): void
    {
        $this->insertParentWithChildren('first', ['pizza']);
        $this->insertParentWithChildren('second', ['pizza', 'pizza']);
        $this->insertParentWithChildren('third', ['pizza']);
        $parentAlias = DBTieBreakerParentModel::MODEL_ALIAS;
        $childrenAlias = self::FILTER_ONLY_ALIAS;

        $queryBuilder = DBTieBreakerParent::createQueryBuilder(true);
        $queryBuilder->leftJoin("$parentAlias.tieBreakerChildren", $childrenAlias)
            ->andWhere("$childrenAlias.label = :label")
            ->setParameter('label', 'pizza')
            ->groupBy("$parentAlias.id")
            ->addGroupBy("$parentAlias.businessKey")
            ->setMaxResults(2);

        $businessKeys = $this->businessKeysInResultOrder($queryBuilder->applyDistinctSubqueryIfNeededAndGetResult(true));
        $this->assertCount(2, $businessKeys);
        $this->assertCount(2, array_unique($businessKeys));
    }

    public function testTheMainQueryKeepsTheWhereOnlyWithoutAFetchJoinedCollection(): void
    {
        $parentAlias = DBTieBreakerParentModel::MODEL_ALIAS;
        $childrenAlias = self::FILTER_ONLY_ALIAS;
        $mainSqlRenderingPagination = new MainSqlRenderingRowNumberPagePagination();

        $filterOnlyQueryBuilder = DBTieBreakerParent::createQueryBuilder(true);
        $filterOnlyQueryBuilder->leftJoin("$parentAlias.tieBreakerChildren", $childrenAlias)
            ->andWhere("$childrenAlias.label LIKE :labelPrefix")
            ->setParameter('labelPrefix', 'p%')
            ->setMaxResults(2);
        $fetchJoinQueryBuilder = clone $filterOnlyQueryBuilder;
        $fetchJoinQueryBuilder->addSelect($childrenAlias);

        $this->assertMatchesRegularExpression('/\bWHERE\b/i', $mainSqlRenderingPagination->renderMainSqlWithoutLimit($filterOnlyQueryBuilder));
        $this->assertDoesNotMatchRegularExpression('/\bWHERE\b/i', $mainSqlRenderingPagination->renderMainSqlWithoutLimit($fetchJoinQueryBuilder));
    }

    public function testAnOrderByInsideAWhereSubselectLeavesThePageOrderIntact(): void
    {
        $this->insertParentWithChildren('b-parent', ['pear']);
        $this->insertParentWithChildren('a-parent', ['plum']);
        $this->insertParentWithChildren('c-parent', ['apple']);
        $parentAlias = DBTieBreakerParentModel::MODEL_ALIAS;
        $childrenAlias = self::FILTER_ONLY_ALIAS;
        $childModelClass = DBTieBreakerChildModel::class;

        $queryBuilder = DBTieBreakerParent::createQueryBuilder(true);
        $queryBuilder->leftJoin("$parentAlias.tieBreakerChildren", $childrenAlias)
            ->andWhere(
                "$parentAlias.id IN (SELECT IDENTITY(subselectChild.tieBreakerParent) FROM $childModelClass subselectChild "
                . 'WHERE subselectChild.label LIKE :labelPrefix ORDER BY subselectChild.label ASC)'
            )
            ->setParameter('labelPrefix', 'p%')
            ->orderBy("$parentAlias.businessKey", 'ASC')
            ->setMaxResults(2);

        $this->assertSame(
            ['a-parent', 'b-parent'],
            $this->businessKeysInResultOrder($queryBuilder->applyDistinctSubqueryIfNeededAndGetResult(true))
        );
    }

    public function testAGroupedQueryWithAnOrderByInsideAWhereSubselectRuns(): void
    {
        // the page rank of a grouped query is MIN(…): placed into the subselect's ORDER BY it would be an aggregate
        // of an outer column inside a WHERE (PostgreSQL refuses it)
        $this->insertParentWithChildren('b-parent', ['pear', 'pizza']);
        $this->insertParentWithChildren('a-parent', ['plum']);
        $this->insertParentWithChildren('c-parent', ['apple']);
        $parentAlias = DBTieBreakerParentModel::MODEL_ALIAS;
        $childrenAlias = self::FILTER_ONLY_ALIAS;
        $childModelClass = DBTieBreakerChildModel::class;

        $queryBuilder = DBTieBreakerParent::createQueryBuilder(true);
        $queryBuilder->leftJoin("$parentAlias.tieBreakerChildren", $childrenAlias)
            ->andWhere(
                "$parentAlias.id IN (SELECT IDENTITY(subselectChild.tieBreakerParent) FROM $childModelClass subselectChild "
                . 'WHERE subselectChild.label LIKE :labelPrefix ORDER BY subselectChild.label ASC)'
            )
            ->setParameter('labelPrefix', 'p%')
            ->groupBy("$parentAlias.id")
            ->addGroupBy("$parentAlias.businessKey")
            ->orderBy("COUNT($childrenAlias.id)", 'DESC')
            ->addOrderBy("$parentAlias.businessKey", 'ASC')
            ->setMaxResults(2);

        $this->assertSame(
            ['b-parent', 'a-parent'],
            $this->businessKeysInResultOrder($queryBuilder->applyDistinctSubqueryIfNeededAndGetResult(true))
        );
    }

    public function testConsecutivePagesReturnEveryMatchingRootOnce(): void
    {
        $this->insertParentWithChildren('alpha', ['pizza', 'pasta', 'pie']);
        $this->insertParentWithChildren('bravo', ['cola']);
        $this->insertParentWithChildren('charlie', ['pizza', 'pizza']);
        $this->insertParentWithChildren('delta', ['pie']);
        $this->insertParentWithChildren('echo', ['pear', 'water']);
        $parentAlias = DBTieBreakerParentModel::MODEL_ALIAS;
        $childrenAlias = self::FILTER_ONLY_ALIAS;

        $businessKeysByPage = [];
        foreach ([0, 2] as $firstResult) {
            $queryBuilder = DBTieBreakerParent::createQueryBuilder(true);
            $queryBuilder->leftJoin("$parentAlias.tieBreakerChildren", $childrenAlias)
                ->andWhere("$childrenAlias.label LIKE :labelPrefix")
                ->setParameter('labelPrefix', 'p%')
                ->orderBy("$parentAlias.businessKey", 'ASC')
                ->setFirstResult($firstResult)
                ->setMaxResults(2);
            $businessKeysByPage[] = $this->businessKeysInResultOrder($queryBuilder->applyDistinctSubqueryIfNeededAndGetResult(true));
        }

        $this->assertSame([['alpha', 'charlie'], ['delta', 'echo']], $businessKeysByPage);
    }

    public function testAParameterUsedInTheJoinConditionAndTheWhereBindsCorrectly(): void
    {
        $this->insertParentWithChildren('prefix-match', ['peach']);
        $this->insertParentWithChildren('exact-match', ['kiwi']);
        $this->insertParentWithChildren('no-match', ['apple']);
        $parentAlias = DBTieBreakerParentModel::MODEL_ALIAS;
        $childrenAlias = self::FILTER_ONLY_ALIAS;

        $queryBuilder = DBTieBreakerParent::createQueryBuilder(true);
        $queryBuilder->leftJoin(
            "$parentAlias.tieBreakerChildren",
            $childrenAlias,
            Join::WITH,
            "$childrenAlias.label LIKE :labelPrefix OR $childrenAlias.label = :exactLabel"
        )
            ->andWhere("$childrenAlias.label LIKE :labelPrefix OR $childrenAlias.label = :exactLabel")
            ->setParameter('labelPrefix', 'p%')
            ->setParameter('exactLabel', 'kiwi')
            ->orderBy("$parentAlias.businessKey", 'ASC')
            ->setMaxResults(2);

        $this->assertSame(
            ['exact-match', 'prefix-match'],
            $this->businessKeysInResultOrder($queryBuilder->applyDistinctSubqueryIfNeededAndGetResult(true))
        );
    }

    public function testAFetchJoinedCollectionWithAnotherFilterOnlyJoinStillLoadsThatJoinUnfiltered(): void
    {
        $this->markTestIncomplete(
            'Known limitation: a query that fetch-joins a collection AND joins another to-many relation only to filter '
            . 'loads its roots without the WHERE, so the filter-only join reads every child row of the page (results '
            . 'are correct, the statement reads more rows than needed). Separate change.'
        );
    }
}
