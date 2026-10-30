<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\ConflictRows;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Tests\Integration\Fixtures\Entities\ConflictRows\AmbiguousConflictRows;

class DBAmbiguousConflictRows extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAmbiguousConflictRow::class;
    public const string BASE_ENTITY_SET_CLASS = AmbiguousConflictRows::class;
}
