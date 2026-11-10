<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\PairRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\PairRows\DBPairParents;
use DDD\Tests\Integration\Fixtures\Services\PairParentsService;

/**
 * @property PairParent[] $elements
 * @method PairParent|null first()
 * @method PairParent[] getElements()
 * @method static PairParentsService getService()
 * @method static DBPairParents getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBPairParents::class)]
class PairParents extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = PairParentsService::class;
}
