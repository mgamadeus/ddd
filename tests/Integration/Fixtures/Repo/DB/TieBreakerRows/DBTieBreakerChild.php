<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerChild;

class DBTieBreakerChild extends DBEntity
{
    public const string BASE_ENTITY_CLASS = TieBreakerChild::class;
    public const string BASE_ORM_MODEL = DBTieBreakerChildModel::class;
}
