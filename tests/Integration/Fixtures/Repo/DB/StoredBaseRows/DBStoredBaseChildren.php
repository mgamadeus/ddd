<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\StoredBaseRows;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Tests\Integration\Fixtures\Entities\StoredBaseRows\StoredBaseChildren;

class DBStoredBaseChildren extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBStoredBaseChild::class;
    public const string BASE_ENTITY_SET_CLASS = StoredBaseChildren::class;
}
