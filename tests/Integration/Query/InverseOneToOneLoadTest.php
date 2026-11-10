<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Entities\QueryOptions\SelectOptions;
use DDD\Tests\Integration\Fixtures\Entities\PairRows\PairDetail;
use DDD\Tests\Integration\Fixtures\Entities\PairRows\PairDetails;
use DDD\Tests\Integration\Fixtures\Entities\PairRows\PairParent;
use DDD\Tests\Integration\Fixtures\Entities\PairRows\PairParents;
use DDD\Tests\Integration\Fixtures\Repo\DB\PairRows\DBPairDetailModel;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * The inverse side of a one-to-one relation (the parent of a row that holds the unique foreign key) is read only
 * when it is asked for: by an expand (joined) or by touching the lazy-loaded property. Loading parents alone reads
 * no detail row.
 */
class InverseOneToOneLoadTest extends IntegrationTestCase
{
    use TieBreakerFixtureTablesTrait;

    protected static function getExtraFixtureEntityClasses(): array
    {
        return [PairParent::class, PairDetail::class];
    }

    public function testLoadingParentsReadsNoDetailRow(): void
    {
        [$firstParentId, $firstDetailId] = $this->insertParentWithDetail(1, 'first');
        [$secondParentId, $secondDetailId] = $this->insertParentWithDetail(2, 'second');
        static::insertRow('PairParents', ['id' => 3, 'label' => 'without detail']);

        $parent = PairParent::getService()->find($firstParentId);
        $this->assertNotNull($parent);
        $this->assertFalse($this->isDetailRowLoaded($firstDetailId), 'a find of the parent must not read its detail');

        $parents = PairParents::getService()->findAll();
        $this->assertSame(3, $parents->count());
        $this->assertFalse($this->isDetailRowLoaded($firstDetailId));
        $this->assertFalse($this->isDetailRowLoaded($secondDetailId), 'a list of parents must not read their details');
    }

    public function testTheDetailLoadsWhenTheLazyLoadedPropertyIsTouched(): void
    {
        [$parentId, $detailId] = $this->insertParentWithDetail(1, 'first');
        static::insertRow('PairParents', ['id' => 2, 'label' => 'without detail']);

        $parent = PairParent::getService()->find($parentId);
        $this->assertSame($detailId, $parent->pairDetail?->id);
        $this->assertSame('detail of first', $parent->pairDetail->content);

        $parentWithoutDetail = PairParent::getService()->find(2);
        $this->assertNull($parentWithoutDetail->pairDetail);
    }

    public function testAnExpandJoinsTheDetail(): void
    {
        [$parentId, $detailId] = $this->insertParentWithDetail(1, 'first');
        static::insertRow('PairParents', ['id' => 2, 'label' => 'without detail']);

        PairParents::setDefaultQueryOptionsSnapshot();
        try {
            $expandOptions = ExpandOptions::fromString('pairDetail');
            PairParents::getDefaultQueryOptions()->setExpand($expandOptions);
            $parents = PairParents::getService()->findAll();
        } finally {
            PairParents::restoreDefaultQueryOptionsSnapshot();
        }
        $this->assertSame(2, $parents->count());
        $this->assertTrue($this->isDetailRowLoaded($detailId), 'the expand reads the detail with the parents');
        $detailIdsByParentId = [];
        foreach ($parents->getElements() as $parent) {
            $detailIdsByParentId[$parent->id] = isset($parent->pairDetail) ? $parent->pairDetail->id : null;
        }
        $this->assertSame([$parentId => $detailId, 2 => null], $detailIdsByParentId);
    }

    public function testTheParentOfADetailIsStillReachable(): void
    {
        [$parentId, $detailId] = $this->insertParentWithDetail(1, 'first');

        $detail = PairDetails::getService()->find($detailId);
        $this->assertSame($parentId, $detail->pairParent->id);
        $this->assertSame('first', $detail->pairParent->label);
    }

    public function testAFindWithASelectHidesTheUnselectedPropertiesLikeAList(): void
    {
        [, $detailId] = $this->insertParentWithDetail(1, 'first');

        PairDetail::setDefaultQueryOptionsSnapshot();
        PairDetails::setDefaultQueryOptionsSnapshot();
        try {
            $selectOptions = SelectOptions::fromString('content');
            PairDetail::getDefaultQueryOptions()->setSelect($selectOptions);
            $listSelectOptions = SelectOptions::fromString('content');
            PairDetails::getDefaultQueryOptions()->setSelect($listSelectOptions);
            $foundDetail = PairDetails::getService()->find($detailId)->toObject(false, forPersistence: false);
            $listedDetail = PairDetails::getService()->findAll()->first()->toObject(false, forPersistence: false);
        } finally {
            PairDetail::restoreDefaultQueryOptionsSnapshot();
            PairDetails::restoreDefaultQueryOptionsSnapshot();
        }
        $this->assertArrayNotHasKey('approved', $listedDetail);
        $this->assertArrayNotHasKey('approved', $foundDetail, 'an unselected property must not appear with its class default');
        $this->assertSame('detail of first', $foundDetail['content']);
        $this->assertSame(array_keys($listedDetail), array_keys($foundDetail));

        $wholeDetail = PairDetails::getService()->find($detailId)->toObject(false, forPersistence: false);
        $this->assertTrue($wholeDetail['approved'], 'a later find without a select shows every property again');
    }

    /**
     * @return int[] The ids of the inserted parent and of its detail
     */
    protected function insertParentWithDetail(int $parentId, string $label): array
    {
        $detailId = $parentId * 100;
        static::insertRow('PairParents', ['id' => $parentId, 'label' => $label]);
        static::insertRow('PairDetails', ['id' => $detailId, 'pairParentId' => $parentId, 'content' => 'detail of ' . $label]);
        return [$parentId, $detailId];
    }

    /** Whether the unit of work holds the detail row, i.e. a statement read it */
    protected function isDetailRowLoaded(int $detailId): bool
    {
        return static::entityManager()->getUnitOfWork()->tryGetById(['id' => $detailId], DBPairDetailModel::class) !== false;
    }
}
