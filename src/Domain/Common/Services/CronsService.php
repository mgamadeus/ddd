<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\CronExecution;
use DDD\Domain\Common\Entities\Crons\Crons;
use DDD\Domain\Common\Repo\DB\Crons\DBCronExecutions;
use DDD\Domain\Common\Repo\DB\Crons\DBCrons;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Doctrine\DBAL\Types\Types;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * @method Cron find(string|int|null $entityId, bool $useEntityRegistrCache = true)
 */
class CronsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = Cron::class;

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
     * Finds the Cron with the given unique name — the handle every operator-facing surface (CLI, admin UI) addresses a
     * Cron by; null when no Cron carries that name.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findByName(string $name): ?Cron
    {
        $dbCrons = new DBCrons();
        $queryBuilder = $dbCrons::createQueryBuilder();
        $baseModelAlias = $dbCrons::getBaseModelAlias();
        $queryBuilder->andWhere("$baseModelAlias.name = :name");
        $queryBuilder->setParameter('name', $name);
        $queryBuilder->setMaxResults(1);
        return $dbCrons->find($queryBuilder)->first();
    }

    /**
     * Deletes ENDED CronExecutions older than 14 days. A RUNNING row is never deleted: one whose runner is gone is
     * closed by {@see self::closeAbandonedCronExecutions()}, so the history shows it.
     */
    public function cleanupCronExecutions(): void
    {
        // DQL, not raw SQL: the identifiers are quoted per engine (PostgreSQL folds unquoted CronExecutions to lower case)
        EntityManagerFactory::getInstance()->createQueryBuilder()
            ->delete(DBCronExecutions::getBaseModel(), 'cronExecution')
            ->where('cronExecution.state = :stateEnded AND cronExecution.executionStartedAt < :nowMinus14Days')
            ->setParameter('stateEnded', CronExecution::STATE_ENDED)
            ->setParameter('nowMinus14Days', (new DateTime('now'))->modify('-14 days'), Types::DATETIME_MUTABLE)
            ->getQuery()
            ->execute();
    }

    /**
     * Closes RUNNING CronExecutions whose runner is gone (killed, its container replaced): a command runs at most
     * `$timeoutPerCronInSeconds` before the runner stops it and closes the row, so a row still RUNNING after that
     * timeout plus {@see CronExecution::ABANDONED_GRACE_SECONDS} has no runner any more. It is closed as ENDED / FAILED
     * with the reason in its output — kept in the history, and its Cron runs again at the next due tick.
     *
     * @param int $timeoutPerCronInSeconds the timeout the runners give one command ({@see Crons::TIMEOUT_PER_CRON_IN_SECONDS})
     * @return int the number of executions closed
     */
    public function closeAbandonedCronExecutions(int $timeoutPerCronInSeconds = Crons::TIMEOUT_PER_CRON_IN_SECONDS): int
    {
        $abandonedAfterSeconds = $timeoutPerCronInSeconds + CronExecution::ABANDONED_GRACE_SECONDS;
        $now = new DateTime('now');
        return (int)EntityManagerFactory::getInstance()->createQueryBuilder()
            ->update(DBCronExecutions::getBaseModel(), 'cronExecution')
            ->set('cronExecution.state', ':stateEnded')
            ->set('cronExecution.executionState', ':executionStateFailed')
            ->set('cronExecution.executionEndedAt', ':now')
            ->set('cronExecution.output', ':output')
            ->where('cronExecution.state = :stateRunning AND cronExecution.executionStartedAt < :abandonedBefore')
            ->setParameter('stateEnded', CronExecution::STATE_ENDED)
            ->setParameter('executionStateFailed', CronExecution::EXECUTION_STATE_FAILED)
            ->setParameter('now', $now, Types::DATETIME_MUTABLE)
            ->setParameter(
                'output',
                '[cron executor] Abandoned: still RUNNING ' . $abandonedAfterSeconds . ' s after its start, longer than a runner lets a'
                . ' command run (' . $timeoutPerCronInSeconds . ' s); its runner ended before it could close the execution'
            )
            ->setParameter('stateRunning', CronExecution::STATE_RUNNING)
            ->setParameter('abandonedBefore', (clone $now)->modify('-' . $abandonedAfterSeconds . ' seconds'), Types::DATETIME_MUTABLE)
            ->getQuery()
            ->execute();
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
        $cronExecutionsModel = DBCronExecutions::getBaseModel();
        $queryBuilder->andWhere(
            "$baseModelAlias.id NOT IN (SELECT executions.cronId from $cronExecutionsModel executions WHERE executions.state = :stateRunning) AND $baseModelAlias.nextExecutionScheduledAt <= :currentDate AND $baseModelAlias.isActive = :isActive"
        );
        $queryBuilder->setParameter('isActive', true, Types::BOOLEAN);
        $queryBuilder->setParameter('currentDate', new DateTime(), Types::DATETIME_MUTABLE);
        $queryBuilder->setParameter('stateRunning', CronExecution::STATE_RUNNING);
        return $dbCrons->find($queryBuilder);
    }
}