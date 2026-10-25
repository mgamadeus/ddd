<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerParents;
use DDD\Tests\Integration\Fixtures\Services\TieBreakerParentsService;

/**
 * @property TieBreakerParent[] $elements
 * @method TieBreakerParent|null first()
 * @method TieBreakerParent[] getElements()
 * @method static TieBreakerParentsService getService()
 * @method static DBTieBreakerParents getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBTieBreakerParents::class)]
class TieBreakerParents extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = TieBreakerParentsService::class;
}
