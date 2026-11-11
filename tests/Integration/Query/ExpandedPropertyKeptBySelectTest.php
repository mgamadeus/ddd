<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Entities\QueryOptions\SelectOptions;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParent;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParents;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * An expanded property stays in the output even when the select does not name it: the expand is an explicit request
 * of the caller, the select only narrows the columns. A property that is neither selected nor expanded stays hidden.
 * Holds for lists, single finds and nested expands.
 */
class ExpandedPropertyKeptBySelectTest extends IntegrationTestCase
{
    use TieBreakerFixtureTablesTrait;

    protected function insertParentWithChildAndGrandchild(): int
    {
        static::insertRow('TieBreakerParents', ['businessKey' => 'expanded-kept']);
        $parentId = $this->findParentIdByBusinessKey('expanded-kept');
        $childId = $parentId * 1000 + 1;
        static::insertRow('TieBreakerChildren', ['id' => $childId, 'tieBreakerParentId' => $parentId, 'label' => 'pizza']);
        static::insertRow('TieBreakerGrandchildren', ['id' => $childId * 1000 + 1, 'tieBreakerChildId' => $childId, 'label' => 'cheese']);
        return $parentId;
    }

    /** @return array<string, mixed> the serialized first parent of a list find */
    protected function serializeFirstParentOfList(string $select, ?string $expand): array
    {
        TieBreakerParents::setDefaultQueryOptionsSnapshot();
        try {
            $appliedQueryOptions = TieBreakerParents::getDefaultQueryOptions();
            $selectOptions = SelectOptions::fromString($select);
            $appliedQueryOptions->setSelect($selectOptions);
            if ($expand !== null) {
                $expandOptions = ExpandOptions::fromString($expand);
                $appliedQueryOptions->setExpand($expandOptions);
            }
            $parents = TieBreakerParent::getService()->findAll();
            $parents->expand();
            $serializedParents = $parents->toObject(forPersistence: false);
        } finally {
            TieBreakerParents::restoreDefaultQueryOptionsSnapshot();
        }
        $serializedParents = json_decode(json_encode($serializedParents), true);
        $this->assertCount(1, $serializedParents['elements']);
        return $serializedParents['elements'][0];
    }

    public function testAnExpandedRelationIsNotHiddenByTheSelect(): void
    {
        $this->insertParentWithChildAndGrandchild();

        $serializedParent = $this->serializeFirstParentOfList('businessKey', 'tieBreakerChildren');

        $this->assertSame('expanded-kept', $serializedParent['businessKey']);
        $this->assertArrayHasKey('tieBreakerChildren', $serializedParent);
        $this->assertSame('pizza', $serializedParent['tieBreakerChildren']['elements'][0]['label']);
    }

    public function testARelationThatIsNotExpandedStaysHidden(): void
    {
        $this->insertParentWithChildAndGrandchild();

        $serializedParent = $this->serializeFirstParentOfList('businessKey', null);

        $this->assertArrayNotHasKey('tieBreakerChildren', $serializedParent);
    }

    public function testANestedExpandIsNotHiddenByTheSelectOfItsExpand(): void
    {
        $this->insertParentWithChildAndGrandchild();

        $serializedParent = $this->serializeFirstParentOfList(
            'businessKey',
            'tieBreakerChildren(select=label;expand=tieBreakerGrandchildren)'
        );

        $serializedChild = $serializedParent['tieBreakerChildren']['elements'][0];
        $this->assertSame('pizza', $serializedChild['label']);
        $this->assertArrayNotHasKey('tieBreakerParentId', $serializedChild);
        $this->assertSame('cheese', $serializedChild['tieBreakerGrandchildren']['elements'][0]['label']);
    }

    public function testASingleFindKeepsAnExpandedRelation(): void
    {
        $parentId = $this->insertParentWithChildAndGrandchild();

        TieBreakerParent::setDefaultQueryOptionsSnapshot();
        try {
            $appliedQueryOptions = TieBreakerParent::getDefaultQueryOptions();
            $selectOptions = SelectOptions::fromString('businessKey');
            $appliedQueryOptions->setSelect($selectOptions);
            $expandOptions = ExpandOptions::fromString('tieBreakerChildren');
            $appliedQueryOptions->setExpand($expandOptions);
            $parent = TieBreakerParent::getService()->find($parentId, false);
            $parent->expand();
            $serializedParent = json_decode(json_encode($parent->toObject(forPersistence: false)), true);
        } finally {
            TieBreakerParent::restoreDefaultQueryOptionsSnapshot();
        }

        $this->assertSame('expanded-kept', $serializedParent['businessKey']);
        $this->assertSame('pizza', $serializedParent['tieBreakerChildren']['elements'][0]['label']);
    }
}
