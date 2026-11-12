<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\Fixtures;

use DDD\Tests\Integration\Fixtures\Repo\DB\ChangeHistoryRows\DBChangeHistoryRow;

/** The application's repository of {@see ApplicationChangeHistoryRow}. */
class DBApplicationChangeHistoryRow extends DBChangeHistoryRow
{
    public const string BASE_ENTITY_CLASS = ApplicationChangeHistoryRow::class;
}
