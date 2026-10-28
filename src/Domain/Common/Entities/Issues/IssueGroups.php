<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Repo\DB\Issues\DBIssueGroups;

/**
 * @property IssueGroup[] $elements;
 * @method IssueGroup[] getElements()
 * @method IssueGroup|null first()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBIssueGroups::class)]
#[QueryOptions(top: 50)]
class IssueGroups extends EntitySet
{
    use QueryOptionsTrait;
}
