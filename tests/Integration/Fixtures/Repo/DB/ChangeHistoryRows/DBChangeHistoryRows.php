<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Repo\DB\ChangeHistoryRows;

use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRows;

class DBChangeHistoryRows extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBChangeHistoryRow::class;
    public const string BASE_ENTITY_SET_CLASS = ChangeHistoryRows::class;
}
