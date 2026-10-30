<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Issues;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Common\Entities\Issues\Issues;

class DBIssues extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBIssue::class;
    public const string BASE_ENTITY_SET_CLASS = Issues::class;
}
