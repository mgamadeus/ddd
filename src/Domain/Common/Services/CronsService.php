<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Domain\Common\Entities\Crons\Crons;
use DDD\Domain\Common\Repo\DB\Crons\DBCron;
use DDD\Domain\Common\Repo\DB\Crons\DBCronExecutionModel;
use DDD\Domain\Common\Repo\DB\Crons\DBCronExecutions;
use DDD\Domain\Common\Repo\DB\Crons\DBCrons;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\Service;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

class CronsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = Cron::class;

    /**
     * Lists all Crons
     * @return Crons
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function list(): Crons
    {
        $dbCrons = new DBCrons();
        return $dbCrons->find();
    }


    /**
     * @return void Deltes CronExecutions older than 14 days and deletes executions that are marked as running after 4 hours
     */
    public function cleanupCronExecutions(): void
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $connection->executeStatement(
            'DELETE FROM ' . DBCronExecutionModel::getTableName()
            . " WHERE (state = '" . CronExecution::STATE_RUNNING . "' AND executionStartedAt <  :nowMinus4Hours) OR (state = '" . CronExecution::STATE_ENDED . "' AND executionStartedAt <  :nowMinus14Days)",
            [
                'nowMinus4Hours' => (new DateTime('now'))->modify('-4 hours'),
                'nowMinus14Days' => (new DateTime('now'))->modify('-14 days')
            ]
        );
    }

    /**
     * Returns Crons scheduled for execution, excluding running Crons
     * @return Crons
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getCronsScheduledForExecution(): Crons
    {
        $dbCrons = new DBCrons();
        $baseModelAlias = $dbCrons::getBaseModelAlias();
        $queryBuilder = $dbCrons::createQueryBuilder();
        $cronExecutionsAlias = DBCronExecutions::getBaseModelAlias();
        $cronExecutionsModel = DBCronExecutions::getBaseModel();
        $queryBuilder->andWhere(
            "{$baseModelAlias}.id NOT IN (SELECT executions.cronId from {$cronExecutionsModel} executions WHERE executions.state = :stateRunning)"
        );
        $queryBuilder->andWhere("{$baseModelAlias}.nextExecutionScheduledAt <= :currentDate");
        $queryBuilder->setParameter('currentDate', new DateTime());
        $queryBuilder->setParameter('stateRunning', CronExecution::STATE_RUNNING);
        return $dbCrons->find($queryBuilder);
    }
}