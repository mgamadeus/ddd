<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerParent;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerParentModel;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * A DISTINCT query with joins runs through the two-step pagination path: paginated, or with a root filter on a
 * fetch-joined collection. On PostgreSQL the page rank the main query is ordered by must then be in its select list
 * (SQLSTATE 42P10 otherwise); the roots come back in the requested order, with complete collections.
 */
class DistinctPaginationTest extends IntegrationTestCase
{
    use TieBreakerFixtureTablesTrait;

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

    /** @return array<string, string[]> the child labels (sorted) per business key, in result order */
    protected function childLabelsByBusinessKeyInResultOrder(iterable $parentModels): array
    {
        $childLabelsByBusinessKey = [];
        foreach ($parentModels as $parentModel) {
            $childLabels = [];
            foreach ($parentModel->tieBreakerChildren as $childModel) {
                $childLabels[] = $childModel->label;
            }
            sort($childLabels);
            $childLabelsByBusinessKey[$parentModel->businessKey] = $childLabels;
        }
        return $childLabelsByBusinessKey;
    }

    public function testADistinctQueryWithAFetchJoinedCollectionAndARootFilterRuns(): void
    {
        $this->insertParentWithChildren('pizza-and-cola', ['pizza', 'cola']);
        $this->insertParentWithChildren('cola-only', ['cola']);
        $this->insertParentWithChildren('pizza-only', ['pizza']);
        $parentAlias = DBTieBreakerParentModel::MODEL_ALIAS;

        foreach (['addSelect', 'comma'] as $selectSyntax) {
            foreach ([null, 2] as $limit) {
                $queryBuilder = DBTieBreakerParent::createQueryBuilder(true);
                $queryBuilder->leftJoin("$parentAlias.tieBreakerChildren", 'children')
                    ->distinct()
                    ->andWhere("children.label = 'pizza'")
                    ->orderBy("$parentAlias.businessKey", 'DESC')
                    ->setMaxResults($limit);
                $selectSyntax === 'comma' ? $queryBuilder->select("$parentAlias, children") : $queryBuilder->addSelect('children');

                $this->assertSame(
                    ['pizza-only' => ['pizza'], 'pizza-and-cola' => ['cola', 'pizza']],
                    $this->childLabelsByBusinessKeyInResultOrder($queryBuilder->applyDistinctSubqueryIfNeededAndGetResult(true)),
                    "select by $selectSyntax, limit " . var_export($limit, true)
                );
                static::entityManager()->clear();
            }
        }
    }

    public function testADistinctPaginatedQueryWithoutAnOrderByRuns(): void
    {
        $this->insertParentWithChildren('first', ['a', 'b']);
        $this->insertParentWithChildren('second', ['c']);
        $this->insertParentWithChildren('third', ['d']);
        $parentAlias = DBTieBreakerParentModel::MODEL_ALIAS;

        $queryBuilder = DBTieBreakerParent::createQueryBuilder(true);
        $queryBuilder->leftJoin("$parentAlias.tieBreakerChildren", 'children')
            ->addSelect('children')
            ->distinct()
            ->setMaxResults(2);

        $childLabelsByBusinessKey = $this->childLabelsByBusinessKeyInResultOrder($queryBuilder->applyDistinctSubqueryIfNeededAndGetResult(true));
        $this->assertCount(2, $childLabelsByBusinessKey);
        foreach ($childLabelsByBusinessKey as $businessKey => $childLabels) {
            $this->assertSame(['first' => ['a', 'b'], 'second' => ['c'], 'third' => ['d']][$businessKey], $childLabels, $businessKey);
        }
    }

    public function testADistinctGroupedPaginatedQueryRuns(): void
    {
        $this->insertParentWithChildren('a-two-matches', ['pizza', 'pizza']);
        $this->insertParentWithChildren('b-one-match', ['pizza', 'cola']);
        $this->insertParentWithChildren('c-no-match', ['cola']);
        $parentAlias = DBTieBreakerParentModel::MODEL_ALIAS;

        $queryBuilder = DBTieBreakerParent::createQueryBuilder(true);
        $queryBuilder->leftJoin("$parentAlias.tieBreakerChildren", 'filterOnlyChildren')
            ->distinct()
            ->andWhere("filterOnlyChildren.label = 'pizza'")
            ->groupBy("$parentAlias.id")
            ->addGroupBy("$parentAlias.businessKey")
            ->orderBy("$parentAlias.businessKey", 'DESC')
            ->setMaxResults(2);

        $businessKeys = [];
        foreach ($queryBuilder->applyDistinctSubqueryIfNeededAndGetResult(true) as $parentModel) {
            $businessKeys[] = $parentModel->businessKey;
        }
        $this->assertSame(['b-one-match', 'a-two-matches'], $businessKeys);
    }
}
