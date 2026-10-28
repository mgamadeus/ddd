<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Repo\DB\Issues\DBIssuesJobStates;

/**
 * @property IssuesJobState[] $elements;
 * @method IssuesJobState[] getElements()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBIssuesJobStates::class)]
class IssuesJobStates extends EntitySet
{
}
