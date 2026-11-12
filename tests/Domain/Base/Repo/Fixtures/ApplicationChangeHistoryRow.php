<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\Fixtures;

use DDD\Tests\Integration\Fixtures\Entities\ChangeHistoryRows\ChangeHistoryRow;

/** An application's subclass of a module entity that took over the module entity's repository. */
class ApplicationChangeHistoryRow extends ChangeHistoryRow
{
}
