<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssueGroups;
use DDD\Domain\Common\Entities\Issues\IssueGroupsFilter;
use DDD\Domain\Common\Entities\Issues\IssueGroupStatusCount;
use DDD\Domain\Common\Entities\Issues\IssueGroupStatusCounts;
use DDD\Domain\Common\Repo\DB\Issues\DBIssueGroup;
use DDD\Domain\Common\Repo\DB\Issues\DBIssueGroups;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The issues registry's root-cause groups: the list (works without the log backend), lookup by hash, and the status
 * changes an admin makes. Rights: admins read (the repositories restrict); the app's admin controller gates the writes.
 */
class IssueGroupsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = IssueGroup::class;

    /**
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findByGroupHash(string $groupHash, ?string $environment = null): ?IssueGroup
    {
        $dbIssueGroup = new DBIssueGroup();
        $queryBuilder = $dbIssueGroup::createQueryBuilder(true);
        $alias = $dbIssueGroup::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.groupHash = :groupHash")->setParameter('groupHash', $groupHash);
        if ($environment !== null) {
            $queryBuilder->andWhere("$alias.environment = :environment")->setParameter('environment', $environment);
        }
        $queryBuilder->orderBy("$alias.lastSeenAt", 'DESC');
        $queryBuilder->setMaxResults(1);
        /** @var IssueGroup|null $issueGroup */
        $issueGroup = $dbIssueGroup->find($queryBuilder, false);
        return $issueGroup;
    }

    /**
     * The groups of one hash across the environments, last seen first.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findGroupsByGroupHash(string $groupHash): IssueGroups
    {
        $dbIssueGroups = new DBIssueGroups();
        $queryBuilder = $dbIssueGroups::createQueryBuilder();
        $alias = $dbIssueGroups::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.groupHash = :groupHash")->setParameter('groupHash', $groupHash);
        $queryBuilder->orderBy("$alias.lastSeenAt", 'DESC');
        return $dbIssueGroups->find($queryBuilder, false);
    }

    /**
     * Groups by last seen, newest first.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function listGroups(IssueGroupsFilter $issueGroupsFilter): IssueGroups
    {
        $dbIssueGroups = new DBIssueGroups();
        $queryBuilder = $dbIssueGroups::createQueryBuilder();
        $alias = $dbIssueGroups::getBaseModelAlias();
        if ($issueGroupsFilter->status !== null) {
            $queryBuilder->andWhere("$alias.status = :status")->setParameter('status', $issueGroupsFilter->status);
        }
        if ($issueGroupsFilter->newSince !== null) {
            $queryBuilder->andWhere("$alias.registeredAt >= :newSince")->setParameter('newSince', $issueGroupsFilter->newSince);
        }
        if ($issueGroupsFilter->release !== null) {
            $queryBuilder->andWhere("($alias.releaseFirstSeen = :release OR $alias.releaseLastSeen = :release)")
                ->setParameter('release', $issueGroupsFilter->release);
        }
        if ($issueGroupsFilter->section !== null) {
            $queryBuilder->andWhere("$alias.section = :section")->setParameter('section', $issueGroupsFilter->section);
        }
        if ($issueGroupsFilter->environment !== null) {
            $queryBuilder->andWhere("$alias.environment = :environment")->setParameter('environment', $issueGroupsFilter->environment);
        }
        $queryBuilder->orderBy("$alias.lastSeenAt", 'DESC');
        $queryBuilder->setMaxResults(max(1, $issueGroupsFilter->limit));
        return $dbIssueGroups->find($queryBuilder, false);
    }

    /**
     * Groups whose alert is due (not ignored).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findGroupsWithPendingAlert(?string $environment = null): IssueGroups
    {
        $dbIssueGroups = new DBIssueGroups();
        $queryBuilder = $dbIssueGroups::createQueryBuilder();
        $alias = $dbIssueGroups::getBaseModelAlias();
        if ($environment !== null) {
            $queryBuilder->andWhere("$alias.environment = :environment")->setParameter('environment', $environment);
        }
        $queryBuilder->andWhere("$alias.alertPending = :alertPending")->setParameter('alertPending', true);
        $queryBuilder->andWhere("$alias.status <> :ignored")->setParameter('ignored', IssueGroup::STATUS_IGNORED);
        $queryBuilder->setMaxResults(500);
        return $dbIssueGroups->find($queryBuilder, false);
    }

    /**
     * Number of new groups (registered outside seed mode) since the given moment, in one environment or all — the
     * explosion guard's input.
     */
    public function countNewGroupsRegisteredSince(DateTime $since, ?string $environment = null): int
    {
        $dbIssueGroups = new DBIssueGroups();
        $queryBuilder = $dbIssueGroups::createQueryBuilder();
        $alias = $dbIssueGroups::getBaseModelAlias();
        $queryBuilder->select("COUNT($alias.id)")->from(DBIssueGroup::BASE_ORM_MODEL, $alias);
        $queryBuilder->andWhere("$alias.registeredAt >= :since")->setParameter('since', $since);
        $queryBuilder->andWhere("$alias.isNew = :isNew")->setParameter('isNew', true);
        if ($environment !== null) {
            $queryBuilder->andWhere("$alias.environment = :environment")->setParameter('environment', $environment);
        }
        return (int)$queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Group counts per environment and status — the environment switch and the status tabs of an admin list.
     */
    public function countGroupsByEnvironmentAndStatus(): IssueGroupStatusCounts
    {
        $dbIssueGroups = new DBIssueGroups();
        $queryBuilder = $dbIssueGroups::createQueryBuilder();
        $alias = $dbIssueGroups::getBaseModelAlias();
        $queryBuilder->select("$alias.environment AS environment, $alias.status AS status, COUNT($alias.id) AS groupCount")
            ->from(DBIssueGroup::BASE_ORM_MODEL, $alias)
            ->groupBy("$alias.environment, $alias.status")
            ->orderBy("$alias.environment")
            ->addOrderBy("$alias.status");
        $issueGroupStatusCounts = new IssueGroupStatusCounts();
        foreach ($queryBuilder->getQuery()->getArrayResult() as $row) {
            $issueGroupStatusCount = new IssueGroupStatusCount(
                $row['environment'] !== null && $row['environment'] !== '' ? (string)$row['environment'] : null,
                (string)$row['status'],
                (int)$row['groupCount'],
            );
            $issueGroupStatusCounts->add($issueGroupStatusCount);
        }
        return $issueGroupStatusCounts;
    }

    /**
     * Gives the groups from before environments (no environment) to the single configured one; returns how many.
     */
    public function adoptGroupsWithoutEnvironment(string $environment): int
    {
        return (int)EntityManagerFactory::getInstance()->createQueryBuilder()
            ->update(DBIssueGroup::BASE_ORM_MODEL, 'issueGroup')
            ->set('issueGroup.environment', ':environment')
            ->where("issueGroup.environment IS NULL OR issueGroup.environment = ''")
            ->setParameter('environment', $environment)
            ->getQuery()
            ->execute();
    }

    /** Marks the group fixed: only an event after now reopens it (a regression) */
    public function resolve(IssueGroup $issueGroup): IssueGroup
    {
        $issueGroup->status = IssueGroup::STATUS_RESOLVED;
        $issueGroup->resolvedAt = DateTime::fromTimestamp(time());
        $issueGroup->alertPending = false;
        $issueGroup->isRegression = false;
        return $issueGroup->update();
    }

    /** The group never alerts again (also not as a regression) */
    public function ignore(IssueGroup $issueGroup): IssueGroup
    {
        $issueGroup->status = IssueGroup::STATUS_IGNORED;
        $issueGroup->alertPending = false;
        return $issueGroup->update();
    }

    public function reopen(IssueGroup $issueGroup): IssueGroup
    {
        $issueGroup->status = IssueGroup::STATUS_OPEN;
        $issueGroup->resolvedAt = null;
        return $issueGroup->update();
    }
}
