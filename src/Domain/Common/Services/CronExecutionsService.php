<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Domain\Common\Entities\Crons\CronExecutions;
use DDD\Domain\Common\Repo\DB\Crons\DBCronExecution;
use DDD\Domain\Common\Repo\DB\Crons\DBCronExecutions;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\Service;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

class CronExecutionsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = CronExecution::class;


    /**
     * Lists all CronExecutions
     * @return CronExecutions
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function list(): CronExecutions
    {
        $dbCronExecutions = new DBCronExecutions();
        return $dbCronExecutions->find();
    }

    /**
     * Returns last execution for Cron
     * @param Cron $cron
     * @return CronExecution|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getLastExecutionForCron(Cron &$cron): ?CronExecution
    {
        $dbCronExecutions = new DBCronExecutions();
        $queryBuilder = $dbCronExecutions::createQueryBuilder();
        $alias = $dbCronExecutions::getBaseModelAlias();
        $queryBuilder->where("{$alias}.cronId = :cronId");
        $queryBuilder->setParameter('cronId', $cron->id);
        $queryBuilder->orderBy("{$alias}.executionStartedAt", 'DESC');
        $queryBuilder->setMaxResults(1);
        $lastExecutions = $dbCronExecutions->find($queryBuilder);
        return $lastExecutions->first();
    }
}