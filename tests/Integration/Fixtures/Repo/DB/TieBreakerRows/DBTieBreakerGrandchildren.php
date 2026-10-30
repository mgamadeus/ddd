<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerGrandchildren;

class DBTieBreakerGrandchildren extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBTieBreakerGrandchild::class;
    public const string BASE_ENTITY_SET_CLASS = TieBreakerGrandchildren::class;
}
