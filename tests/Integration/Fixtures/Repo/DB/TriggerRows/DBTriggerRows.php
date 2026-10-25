<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\TriggerRows;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Tests\Integration\Fixtures\Entities\TriggerRows\TriggerRows;

class DBTriggerRows extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBTriggerRow::class;
    public const string BASE_ENTITY_SET_CLASS = TriggerRows::class;
}
