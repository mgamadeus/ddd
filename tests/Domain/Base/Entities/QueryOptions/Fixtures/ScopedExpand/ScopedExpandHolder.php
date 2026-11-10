<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ScopedExpand;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/** Expands its items through a load method that records the defaults it sees */
class ScopedExpandHolder extends Entity
{
    use QueryOptionsTrait;

    /** @var int|null The top of ScopedExpandItems' defaults while the load method ran */
    public static ?int $topSeenByLoadMethod = null;

    #[LazyLoad(repoType: LazyLoadRepo::CLASS_METHOD, loadMethod: 'loadItems')]
    public ?ScopedExpandItems $items = null;

    public function loadItems(): ScopedExpandItems
    {
        self::$topSeenByLoadMethod = ScopedExpandItems::getDefaultQueryOptions()->getTop();
        return new ScopedExpandItems();
    }
}
