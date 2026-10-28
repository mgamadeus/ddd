<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssueGroups;
use DDD\Domain\Common\Entities\Issues\IssueGroupsFilter;
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
    public function findByGroupHash(string $groupHash): ?IssueGroup
    {
        $dbIssueGroup = new DBIssueGroup();
        $queryBuilder = $dbIssueGroup::createQueryBuilder(true);
        $alias = $dbIssueGroup::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.groupHash = :groupHash")->setParameter('groupHash', $groupHash);
        /** @var IssueGroup|null $issueGroup */
        $issueGroup = $dbIssueGroup->find($queryBuilder, false);
        return $issueGroup;
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
    public function findGroupsWithPendingAlert(): IssueGroups
    {
        $dbIssueGroups = new DBIssueGroups();
        $queryBuilder = $dbIssueGroups::createQueryBuilder();
        $alias = $dbIssueGroups::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.alertPending = :alertPending")->setParameter('alertPending', true);
        $queryBuilder->andWhere("$alias.status <> :ignored")->setParameter('ignored', IssueGroup::STATUS_IGNORED);
        $queryBuilder->setMaxResults(500);
        return $dbIssueGroups->find($queryBuilder, false);
    }

    /**
     * Number of new groups (registered outside seed mode) since the given moment — the explosion guard's input.
     */
    public function countNewGroupsRegisteredSince(DateTime $since): int
    {
        $dbIssueGroups = new DBIssueGroups();
        $queryBuilder = $dbIssueGroups::createQueryBuilder();
        $alias = $dbIssueGroups::getBaseModelAlias();
        $queryBuilder->select("COUNT($alias.id)")->from(DBIssueGroup::BASE_ORM_MODEL, $alias);
        $queryBuilder->andWhere("$alias.registeredAt >= :since")->setParameter('since', $since);
        $queryBuilder->andWhere("$alias.isNew = :isNew")->setParameter('isNew', true);
        return (int)$queryBuilder->getQuery()->getSingleScalarResult();
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
