<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\PairRows;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Repo\DB\PairRows\DBPairDetails;
use DDD\Tests\Integration\Fixtures\Services\PairDetailsService;

/**
 * @property PairDetail[] $elements
 * @method PairDetail|null first()
 * @method PairDetail[] getElements()
 * @method static PairDetailsService getService()
 * @method static DBPairDetails getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBPairDetails::class)]
class PairDetails extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = PairDetailsService::class;
}
