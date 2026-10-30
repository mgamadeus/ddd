<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerChildren;
use DDD\Tests\Integration\Fixtures\Services\TieBreakerChildrenService;

/**
 * @property TieBreakerChild[] $elements
 * @method TieBreakerChild|null first()
 * @method TieBreakerChild[] getElements()
 * @method static TieBreakerChildrenService getService()
 * @method static DBTieBreakerChildren getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBTieBreakerChildren::class)]
class TieBreakerChildren extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = TieBreakerChildrenService::class;
}
