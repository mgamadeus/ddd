<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerGrandchildren;
use DDD\Tests\Integration\Fixtures\Services\TieBreakerGrandchildrenService;

/**
 * @property TieBreakerGrandchild[] $elements
 * @method TieBreakerGrandchild|null first()
 * @method TieBreakerGrandchild[] getElements()
 * @method static TieBreakerGrandchildrenService getService()
 * @method static DBTieBreakerGrandchildren getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBTieBreakerGrandchildren::class)]
class TieBreakerGrandchildren extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = TieBreakerGrandchildrenService::class;
}
