<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Issues\Issue;
use DDD\Domain\Common\Repo\DB\Issues\DBIssue;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The issues of the registry's groups (one per issue hash).
 */
class IssuesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = Issue::class;

    /**
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findByIssueHash(string $issueHash): ?Issue
    {
        $dbIssue = new DBIssue();
        $queryBuilder = $dbIssue::createQueryBuilder(true);
        $alias = $dbIssue::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.issueHash = :issueHash")->setParameter('issueHash', $issueHash);
        /** @var Issue|null $issue */
        $issue = $dbIssue->find($queryBuilder, false);
        return $issue;
    }
}
