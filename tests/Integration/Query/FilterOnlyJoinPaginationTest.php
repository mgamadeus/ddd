<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerParent;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerParentModel;
use DDD\Tests\Integration\IntegrationTestCase;

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
        if (static::currentDriver() === 'pdo_pgsql') {
            $this->markTestSkipped(
                'A paginated GROUP BY query with joins does not run on PostgreSQL yet: the page rank the main query is '
                . 'sorted by first (page.dctrn_minrownum) is not in its GROUP BY (SQLSTATE 42803).'
            );
        }
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
}
