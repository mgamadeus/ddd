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
    public const string DEFAULT_ENTITY_CLASS = CronExecution::class;


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
     * The most recent CronExecutions, newest first — optionally for ONE Cron and/or ONE execution state
     * ({@see CronExecution::EXECUTION_STATE_SUCCESSFUL} / {@see CronExecution::EXECUTION_STATE_FAILED}). The read
     * behind "did my cron run, and how did it go" (the `app:crons:executions` command, an admin executions view).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findRecentExecutions(?Cron $cron = null, int $limit = 20, ?string $executionState = null): CronExecutions
    {
        $dbCronExecutions = new DBCronExecutions();
        $queryBuilder = $dbCronExecutions::createQueryBuilder();
        $alias = $dbCronExecutions::getBaseModelAlias();
        if ($cron instanceof Cron) {
            $queryBuilder->andWhere("$alias.cronId = :cronId");
            $queryBuilder->setParameter('cronId', $cron->id);
        }
        if ($executionState !== null && $executionState !== '') {
            $queryBuilder->andWhere("$alias.executionState = :executionState");
            $queryBuilder->setParameter('executionState', $executionState);
        }
        $queryBuilder->orderBy("$alias.executionStartedAt", 'DESC');
        $queryBuilder->setMaxResults(max(1, $limit));
        return $dbCronExecutions->find($queryBuilder);
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
        $queryBuilder->where("$alias.cronId = :cronId");
        $queryBuilder->setParameter('cronId', $cron->id);
        $queryBuilder->orderBy("$alias.executionStartedAt", 'DESC');
        $queryBuilder->setMaxResults(1);
        $lastExecutions = $dbCronExecutions->find($queryBuilder);
        return $lastExecutions->first();
    }
}