<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
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
    public function findByIssueHash(string $issueHash, ?string $environment = null): ?Issue
    {
        $dbIssue = new DBIssue();
        $queryBuilder = $dbIssue::createQueryBuilder(true);
        $alias = $dbIssue::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.issueHash = :issueHash")->setParameter('issueHash', $issueHash);
        if ($environment !== null) {
            $queryBuilder->andWhere("$alias.environment = :environment")->setParameter('environment', $environment);
        }
        $queryBuilder->setMaxResults(1);
        /** @var Issue|null $issue */
        $issue = $dbIssue->find($queryBuilder, false);
        return $issue;
    }

    /**
     * Gives the issues from before environments (no environment) to the single configured one; returns how many.
     */
    public function adoptIssuesWithoutEnvironment(string $environment): int
    {
        return (int)EntityManagerFactory::getInstance()->createQueryBuilder()
            ->update(DBIssue::BASE_ORM_MODEL, 'issue')
            ->set('issue.environment', ':environment')
            ->where("issue.environment IS NULL OR issue.environment = ''")
            ->setParameter('environment', $environment)
            ->getQuery()
            ->execute();
    }
}
