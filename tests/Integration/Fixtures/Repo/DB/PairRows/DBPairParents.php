<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\PairRows;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Tests\Integration\Fixtures\Entities\PairRows\PairParents;

class DBPairParents extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBPairParent::class;
    public const string BASE_ENTITY_SET_CLASS = PairParents::class;
}
