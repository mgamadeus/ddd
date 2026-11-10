<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\PairRows;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Tests\Integration\Fixtures\Entities\PairRows\PairDetail;

class DBPairDetail extends DBEntity
{
    public const string BASE_ENTITY_CLASS = PairDetail::class;
    public const string BASE_ORM_MODEL = DBPairDetailModel::class;
}
