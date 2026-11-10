<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerChild;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParent;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParents;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerChildren;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerChildModel;
use DDD\Tests\Integration\IntegrationTestCase;
use ReflectionProperty;

/**
 * A relation becomes a property of a loaded entity only when the query of that load fetch-joined it. What an earlier
 * query of the request left in Doctrine's unit of work (the parent row another step loaded, a collection another
 * list expanded) does not change the shape of a later load.
 */
class RelationMappedOnlyWhenFetchJoinedTest extends IntegrationTestCase
{
    use TieBreakerFixtureTablesTrait;

    protected function tearDown(): void
    {
        DatabaseRepoEntity::$mapRelationsLoadedByEarlierQueries = null;
        parent::tearDown();
    }

    /** Two parents with one child each; returns the id of the first parent. */
    protected function insertTwoParentsWithOneChildEach(): int
    {
        static::insertRow('TieBreakerParents', ['businessKey' => 'loaded-before']);
        static::insertRow('TieBreakerParents', ['businessKey' => 'never-loaded']);
        $loadedBeforeParentId = $this->findParentIdByBusinessKey('loaded-before');
        $neverLoadedParentId = $this->findParentIdByBusinessKey('never-loaded');
        static::insertRow('TieBreakerChildren', ['id' => 1, 'tieBreakerParentId' => $loadedBeforeParentId, 'label' => 'first']);
        static::insertRow('TieBreakerChildren', ['id' => 2, 'tieBreakerParentId' => $neverLoadedParentId, 'label' => 'second']);
        return $loadedBeforeParentId;
    }

    /** @return TieBreakerChild[] by label */
    protected function findChildren(): array
    {
        $children = TieBreakerChild::getService()->findAll();
        $childrenByLabel = [];
        foreach ($children->getElements() as $child) {
            $childrenByLabel[$child->label] = $child;
        }
        return $childrenByLabel;
    }

    protected function isPropertySet(object $entity, string $propertyName): bool
    {
        return (new ReflectionProperty($entity, $propertyName))->isInitialized($entity);
    }

    public function testAToOneRelationAnEarlierQueryLoadedIsNotMappedWithoutExpand(): void
    {
        $loadedBeforeParentId = $this->insertTwoParentsWithOneChildEach();
        $this->assertNotNull(TieBreakerParent::getService()->find($loadedBeforeParentId));

        $children = $this->findChildren();

        $this->assertFalse($this->isPropertySet($children['first'], 'tieBreakerParent'));
        $this->assertFalse($this->isPropertySet($children['second'], 'tieBreakerParent'));
        $this->assertArrayNotHasKey('tieBreakerParent', (array)$children['first']->toObject(forPersistence: false));
        // the explicit access still lazy-loads it
        $this->assertSame('loaded-before', $children['first']->tieBreakerParent->businessKey);
    }

    public function testASingleFindDoesNotMapARelationAnEarlierQueryLoaded(): void
    {
        $loadedBeforeParentId = $this->insertTwoParentsWithOneChildEach();
        $this->assertNotNull(TieBreakerParent::getService()->find($loadedBeforeParentId));

        $child = TieBreakerChild::getService()->find(1);

        $this->assertFalse($this->isPropertySet($child, 'tieBreakerParent'));
    }

    public function testAFetchJoinOfAServiceQueryIsMappedAndAFilterOnlyJoinIsNot(): void
    {
        $this->insertTwoParentsWithOneChildEach();
        $childAlias = DBTieBreakerChildModel::MODEL_ALIAS;

        $fetchJoinQueryBuilder = DBTieBreakerChildren::createQueryBuilder(true);
        $fetchJoinQueryBuilder->leftJoin("$childAlias.tieBreakerParent", 'fetchedParent')->addSelect('fetchedParent');
        $this->assertSame(['tieBreakerParent'], $fetchJoinQueryBuilder->getFetchJoinedRelationPaths());
        $fetchJoinedChildren = (new DBTieBreakerChildren())->find($fetchJoinQueryBuilder, false);
        foreach ($fetchJoinedChildren->getElements() as $child) {
            $this->assertTrue($this->isPropertySet($child, 'tieBreakerParent'));
        }

        // the parents are in the unit of work now; a join that only filters does not map them
        $filterJoinQueryBuilder = DBTieBreakerChildren::createQueryBuilder(true);
        $filterJoinQueryBuilder->leftJoin("$childAlias.tieBreakerParent", 'filterParent')
            ->andWhere("filterParent.businessKey = 'loaded-before'");
        $this->assertSame([], $filterJoinQueryBuilder->getFetchJoinedRelationPaths());
        $filteredChildren = (new DBTieBreakerChildren())->find($filterJoinQueryBuilder, false);
        $this->assertCount(1, $filteredChildren->getElements());
        $this->assertFalse($this->isPropertySet($filteredChildren->getElements()[0], 'tieBreakerParent'));
    }

    public function testACollectionAnEarlierListExpandedIsNotMappedWithoutExpand(): void
    {
        $this->insertTwoParentsWithOneChildEach();
        TieBreakerParents::setDefaultQueryOptionsSnapshot();
        try {
            $expandOptions = ExpandOptions::fromString('tieBreakerChildren');
            TieBreakerParents::getDefaultQueryOptions()->setExpand($expandOptions);
            $expandedParents = TieBreakerParent::getService()->findAll();
        } finally {
            TieBreakerParents::restoreDefaultQueryOptionsSnapshot();
        }
        foreach ($expandedParents->getElements() as $parent) {
            $this->assertTrue($this->isPropertySet($parent, 'tieBreakerChildren'));
            // the inverse side of the fetched relation points back to the parent it was loaded with
            $this->assertSame($parent, $parent->tieBreakerChildren->getElements()[0]->tieBreakerParent);
        }

        $parents = TieBreakerParent::getService()->findAll();

        foreach ($parents->getElements() as $parent) {
            $this->assertFalse($this->isPropertySet($parent, 'tieBreakerChildren'));
        }
    }

    public function testTheConfigSwitchMapsRelationsOfEarlierQueriesAgain(): void
    {
        $loadedBeforeParentId = $this->insertTwoParentsWithOneChildEach();
        $this->assertNotNull(TieBreakerParent::getService()->find($loadedBeforeParentId));
        DatabaseRepoEntity::$mapRelationsLoadedByEarlierQueries = true;

        $children = $this->findChildren();

        $this->assertTrue($this->isPropertySet($children['first'], 'tieBreakerParent'));
        $this->assertFalse($this->isPropertySet($children['second'], 'tieBreakerParent'));
    }
}
