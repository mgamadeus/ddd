<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParent;

class DBTieBreakerParent extends DBEntity
{
    public const string BASE_ENTITY_CLASS = TieBreakerParent::class;
    public const string BASE_ORM_MODEL = DBTieBreakerParentModel::class;
}
