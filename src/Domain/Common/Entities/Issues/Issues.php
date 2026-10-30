<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Common\Repo\DB\Issues\DBIssues;

/**
 * @property Issue[] $elements;
 * @method Issue[] getElements()
 * @method Issue|null first()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBIssues::class)]
#[QueryOptions(top: 50)]
class Issues extends EntitySet
{
    use QueryOptionsTrait;
}
