<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Repo\DB\Crons\DBCronExecutions;
use DDD\Domain\Common\Services\CronExecutionsService;

/**
 * @property Cron[] $elements;
 * @method CronExecution getByUniqueKey(string $uniqueKey)
 * @method CronExecution first()
 * @method CronExecution[] getElements()
 * @method static CronExecutionsService getService()
 * @method static DBCronExecutions getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBCronExecutions::class)]
#[QueryOptions(top: 10)]
class CronExecutions extends EntitySet
{
    use QueryOptionsTrait;
}