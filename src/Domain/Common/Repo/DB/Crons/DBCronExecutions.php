<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Crons;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Crons\CronExecutions;

/**
 * @method CronExecutions find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBCronExecutions extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBCronExecution::class;
    public const string BASE_ENTITY_SET_CLASS = CronExecutions::class;


}