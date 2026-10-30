<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerChildren;

class DBTieBreakerChildren extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBTieBreakerChild::class;
    public const string BASE_ENTITY_SET_CLASS = TieBreakerChildren::class;
}
