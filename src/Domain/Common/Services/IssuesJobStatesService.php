<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Issues\IssuesJobState;
use DDD\Domain\Common\Repo\DB\Issues\DBIssuesJobState;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The registry job's state row.
 */
class IssuesJobStatesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = IssuesJobState::class;

    /**
     * The state row (a new, unsaved one before the job's first run).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function getState(string $name = IssuesJobState::DEFAULT_NAME): IssuesJobState
    {
        $dbIssuesJobState = new DBIssuesJobState();
        $queryBuilder = $dbIssuesJobState::createQueryBuilder(true);
        $alias = $dbIssuesJobState::getBaseModelAlias();
        $queryBuilder->andWhere("$alias.name = :name")->setParameter('name', $name);
        /** @var IssuesJobState|null $issuesJobState */
        $issuesJobState = $dbIssuesJobState->find($queryBuilder, false);
        if ($issuesJobState === null) {
            $issuesJobState = new IssuesJobState();
            $issuesJobState->name = $name;
        }
        return $issuesJobState;
    }
}
