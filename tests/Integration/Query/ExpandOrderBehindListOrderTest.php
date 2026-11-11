<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerChild;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParent;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParents;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * A list with its own orderBy and an expand that orders its children: the list is sorted by the list's orderBy, the
 * children of each element by the expand's. The expand's ordering used to stand first in the ORDER BY, so the list
 * came back in the order of its children (elements without children first or last).
 */
class ExpandOrderBehindListOrderTest extends IntegrationTestCase
{
    use TieBreakerFixtureTablesTrait;

    /** Three parents: `a` with the children z and b, `b` without children, `c` with the child m */
    protected function insertParentsWithChildren(): void
    {
        foreach (['a' => ['z', 'b'], 'b' => [], 'c' => ['m']] as $businessKey => $childLabels) {
            static::insertRow('TieBreakerParents', ['businessKey' => $businessKey]);
            $parentId = $this->findParentIdByBusinessKey($businessKey);
            foreach ($childLabels as $childLabel) {
                static::insertRow('TieBreakerChildren', ['tieBreakerParentId' => $parentId, 'label' => $childLabel]);
            }
        }
    }

    /** @return string[] The business keys of the loaded parents, each followed by its children's labels */
    protected function loadParentsWithChildLabels(?string $orderBy, ?int $limit, int $offset = 0): array
    {
        TieBreakerParents::setDefaultQueryOptionsSnapshot();
        try {
            $appliedQueryOptions = TieBreakerParents::getDefaultQueryOptions();
            $expandOptions = ExpandOptions::fromString('tieBreakerChildren(orderBy=label asc)');
            $appliedQueryOptions->setExpand($expandOptions);
            if ($orderBy !== null) {
                $orderByOptions = OrderByOptions::fromString($orderBy);
                $appliedQueryOptions->setOrderBy($orderByOptions);
            }
            $parents = TieBreakerParent::getService()->findAll(offset: $offset, limit: $limit);
        } finally {
            TieBreakerParents::restoreDefaultQueryOptionsSnapshot();
        }
        $parentsWithChildLabels = [];
        foreach ($parents->getElements() as $parent) {
            /** @var TieBreakerParent $parent */
            $childLabels = array_map(
                static fn (TieBreakerChild $child): string => (string)$child->label,
                $parent->tieBreakerChildren->getElements()
            );
            $parentsWithChildLabels[] = $parent->businessKey . ':' . implode(',', $childLabels);
        }
        return $parentsWithChildLabels;
    }

    public function testTheListIsSortedByItsOwnOrderByAndTheChildrenByTheExpands(): void
    {
        $this->insertParentsWithChildren();

        self::assertSame(['c:m', 'b:', 'a:b,z'], $this->loadParentsWithChildLabels('businessKey desc', null));
        self::assertSame(['a:b,z', 'b:', 'c:m'], $this->loadParentsWithChildLabels('businessKey asc', null));
    }

    public function testAListWithoutOrderByKeepsItsIdOrder(): void
    {
        $this->insertParentsWithChildren();

        self::assertSame(['a:b,z', 'b:', 'c:m'], $this->loadParentsWithChildLabels(null, null));
        self::assertSame(['a:b,z', 'b:'], $this->loadParentsWithChildLabels(null, 2));
    }

    public function testAPageOfTheListFollowsTheListsOrderBy(): void
    {
        $this->insertParentsWithChildren();

        self::assertSame(['c:m', 'b:'], $this->loadParentsWithChildLabels('businessKey desc', 2));
        self::assertSame(['a:b,z'], $this->loadParentsWithChildLabels('businessKey desc', 2, 2));
    }
}
