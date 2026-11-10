<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\PairRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\PairRows\PairParent;

class DBPairParent extends DBEntity
{
    public const string BASE_ENTITY_CLASS = PairParent::class;
    public const string BASE_ORM_MODEL = DBPairParentModel::class;
}
