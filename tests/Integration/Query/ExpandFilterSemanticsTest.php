<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerChild;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerGrandchild;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParent;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParents;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerParent;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerParentModel;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * OData semantics of a root filter on an expanded collection: `$filter=children.label eq 'pizza'` selects the roots
 * that have such a child, and every selected root comes back with ALL its children. Filtering the children is the
 * job of the expand's own filter (`$expand=children(filters=label eq 'pizza')`). Conditions on the same collection
 * in one filter hold for one and the same child. Same result on both engines, paginated or not.
 */
class ExpandFilterSemanticsTest extends IntegrationTestCase
{
    use TieBreakerFixtureTablesTrait;

    /** @var array<string, int> parent id by business key */
    protected array $parentIds = [];

    /** @var array<string, int> child id by "<businessKey>:<label>" */
    protected array $childIds = [];

    /**
     * pizza-and-cola: children pizza, cola (pizza has grandchild "cheese")
     * cola-only:      child cola
     * pizza-only:     child pizza
     */
    protected function insertMenu(): void
    {
        $menu = ['pizza-and-cola' => ['pizza', 'cola'], 'cola-only' => ['cola'], 'pizza-only' => ['pizza']];
        foreach ($menu as $businessKey => $labels) {
            static::insertRow('TieBreakerParents', ['businessKey' => $businessKey]);
            $parentId = $this->findParentIdByBusinessKey($businessKey);
            $this->parentIds[$businessKey] = $parentId;
            foreach ($labels as $position => $label) {
                $childId = $parentId * 1000 + $position + 1;
                $this->childIds["$businessKey:$label"] = $childId;
                static::insertRow('TieBreakerChildren', ['id' => $childId, 'tieBreakerParentId' => $parentId, 'label' => $label]);
            }
        }
        static::insertRow('TieBreakerGrandchildren', [
            'id' => $this->childIds['pizza-and-cola:pizza'] * 1000 + 1,
            'tieBreakerChildId' => $this->childIds['pizza-and-cola:pizza'],
            'label' => 'cheese',
        ]);
        static::insertRow('TieBreakerGrandchildren', [
            'id' => $this->childIds['pizza-and-cola:pizza'] * 1000 + 2,
            'tieBreakerChildId' => $this->childIds['pizza-and-cola:pizza'],
            'label' => 'basil',
        ]);
    }

    /** @return TieBreakerParent[] by business key, in result order */
    protected function findParents(string $expand, ?string $filter, ?int $limit = null): array
    {
        TieBreakerParents::setDefaultQueryOptionsSnapshot();
        try {
            $appliedQueryOptions = TieBreakerParents::getDefaultQueryOptions();
            $expandOptions = ExpandOptions::fromString($expand);
            $appliedQueryOptions->setExpand($expandOptions);
            if ($filter !== null) {
                $filtersOptions = FiltersOptions::fromString($filter);
                $appliedQueryOptions->setFilters($filtersOptions);
            }
            $parents = $limit === null
                ? TieBreakerParent::getService()->findAll()
                : TieBreakerParent::getService()->findAll(offset: 0, limit: $limit);
        } finally {
            TieBreakerParents::restoreDefaultQueryOptionsSnapshot();
        }
        $parentsByBusinessKey = [];
        foreach ($parents?->getElements() ?? [] as $parent) {
            $parentsByBusinessKey[$parent->businessKey] = $parent;
        }
        return $parentsByBusinessKey;
    }

    /** @return string[] the parent's child labels, id-ascending */
    protected function childLabels(TieBreakerParent $parent): array
    {
        return array_map(
            static fn(TieBreakerChild $child): string => $child->label,
            $parent->tieBreakerChildren->getElements()
        );
    }

    public function testARootFilterOnAChildSelectsTheRootsAndKeepsAllTheirChildren(): void
    {
        $this->insertMenu();

        $parents = $this->findParents('tieBreakerChildren', "tieBreakerChildren.label eq 'pizza'");

        $this->assertEqualsCanonicalizing(['pizza-and-cola', 'pizza-only'], array_keys($parents));
        $this->assertSame(['pizza', 'cola'], $this->childLabels($parents['pizza-and-cola']));
        $this->assertSame(['pizza'], $this->childLabels($parents['pizza-only']));
    }

    public function testTheExpandFilterStillFiltersTheChildrenAndKeepsEveryRoot(): void
    {
        $this->insertMenu();

        $parents = $this->findParents("tieBreakerChildren(filters=label eq 'pizza')", null);

        $this->assertEqualsCanonicalizing(['pizza-and-cola', 'cola-only', 'pizza-only'], array_keys($parents));
        $this->assertSame(['pizza'], $this->childLabels($parents['pizza-and-cola']));
        $this->assertSame([], $this->childLabels($parents['cola-only']));
    }

    /** Two conditions on the collection in one filter must hold for the same child, not for any two children. */
    public function testConditionsOnTheSameCollectionHoldForOneAndTheSameChild(): void
    {
        $this->insertMenu();
        $colaId = $this->childIds['pizza-and-cola:cola'];

        $parents = $this->findParents(
            'tieBreakerChildren',
            "tieBreakerChildren.label eq 'pizza' and tieBreakerChildren.id eq '$colaId'"
        );

        $this->assertSame([], array_keys($parents));
    }

    public function testARootFilterThroughTwoCollectionsKeepsChildrenAndGrandchildrenComplete(): void
    {
        $this->insertMenu();

        $parents = $this->findParents(
            'tieBreakerChildren(expand=tieBreakerGrandchildren)',
            "tieBreakerChildren.tieBreakerGrandchildren.label eq 'cheese'"
        );

        $this->assertSame(['pizza-and-cola'], array_keys($parents));
        $this->assertSame(['pizza', 'cola'], $this->childLabels($parents['pizza-and-cola']));
        $pizza = $parents['pizza-and-cola']->tieBreakerChildren->getElements()[0];
        $this->assertSame(
            ['cheese', 'basil'],
            array_map(static fn(TieBreakerGrandchild $grandchild): string => $grandchild->label, $pizza->tieBreakerGrandchildren->getElements())
        );
    }

    public function testAPaginatedRootFilterOnAChildKeepsAllChildren(): void
    {
        $this->insertMenu();

        $parents = $this->findParents('tieBreakerChildren', "tieBreakerChildren.label eq 'pizza'", limit: 1);

        $this->assertSame(['pizza-and-cola'], array_keys($parents));
        $this->assertSame(['pizza', 'cola'], $this->childLabels($parents['pizza-and-cola']));
    }

    /**
     * Without a limit the same rule holds: a WHERE on a query that fetch-joins a collection selects the roots, the
     * collection comes back complete. A query without a collection join keeps its single statement.
     */
    public function testAnUnpaginatedQueryWithAFetchJoinedCollectionKeepsAllChildren(): void
    {
        $this->insertMenu();
        $parentAlias = DBTieBreakerParentModel::MODEL_ALIAS;
        $childrenAlias = $parentAlias . '_children';

        $queryBuilder = DBTieBreakerParent::createQueryBuilder(true);
        $queryBuilder->leftJoin("$parentAlias.tieBreakerChildren", $childrenAlias)
            ->addSelect($childrenAlias)
            ->andWhere("$childrenAlias.label = :label")
            ->setParameter('label', 'pizza');
        $this->assertNull($queryBuilder->getMaxResults());
        $this->assertTrue($queryBuilder->hasFetchJoinedCollection());

        $childLabelsByBusinessKey = [];
        foreach ($queryBuilder->applyDistinctSubqueryIfNeededAndGetResult(true) as $parentModel) {
            $childLabelsByBusinessKey[$parentModel->businessKey] = array_map(
                static fn($childModel): string => $childModel->label,
                $parentModel->tieBreakerChildren->toArray()
            );
        }
        ksort($childLabelsByBusinessKey);

        $this->assertSame(['pizza-and-cola', 'pizza-only'], array_keys($childLabelsByBusinessKey));
        $this->assertEqualsCanonicalizing(['pizza', 'cola'], $childLabelsByBusinessKey['pizza-and-cola']);
    }

    public function testAJoinThatIsNotSelectedIsNoFetchJoinedCollection(): void
    {
        $parentAlias = DBTieBreakerParentModel::MODEL_ALIAS;
        $queryBuilder = DBTieBreakerParent::createQueryBuilder(true);
        $queryBuilder->leftJoin("$parentAlias.tieBreakerChildren", 'filterOnlyChildren')
            ->andWhere("filterOnlyChildren.label = 'pizza'");

        $this->assertFalse($queryBuilder->hasFetchJoinedCollection());
    }

    /** A root qualifying through several matching children is still one root, in the list and in the count. */
    public function testARootWithSeveralMatchingChildrenIsCountedOnce(): void
    {
        $this->insertMenu();
        $parentId = $this->parentIds['pizza-only'];
        static::insertRow('TieBreakerChildren', ['id' => $parentId * 1000 + 9, 'tieBreakerParentId' => $parentId, 'label' => 'pizza']);

        TieBreakerParents::setDefaultQueryOptionsSnapshot();
        try {
            $appliedQueryOptions = TieBreakerParents::getDefaultQueryOptions();
            $expandOptions = ExpandOptions::fromString('tieBreakerChildren');
            $appliedQueryOptions->setExpand($expandOptions);
            $filtersOptions = FiltersOptions::fromString("tieBreakerChildren.label eq 'pizza'");
            $appliedQueryOptions->setFilters($filtersOptions);
            $count = TieBreakerParent::getService()->countAll();
            $parents = TieBreakerParent::getService()->findAll();
        } finally {
            TieBreakerParents::restoreDefaultQueryOptionsSnapshot();
        }

        $this->assertSame(2, $count);
        $this->assertCount(2, $parents->getElements());
    }
}
