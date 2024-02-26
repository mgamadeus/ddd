<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Crons;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Crons\Crons;

/**
 * @method Crons find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBCrons extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBCron::class;
    public const BASE_ENTITY_SET_CLASS = Crons::class;
}