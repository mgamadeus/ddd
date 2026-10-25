<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\ConflictRows;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Tests\Integration\Fixtures\Entities\ConflictRows\ConflictAttributeRows;

class DBConflictAttributeRows extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBConflictAttributeRow::class;
    public const string BASE_ENTITY_SET_CLASS = ConflictAttributeRows::class;
}
