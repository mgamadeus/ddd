<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\ChangeHistoryRows;

/** An application's class of a package entity: the same short name, a column added, an EntitySet class of its own */
class ChangeHistoryRow extends \DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRow
{
    public ?int $applicationScopeId = null;
}
