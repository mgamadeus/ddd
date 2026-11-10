<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\LegacyCounterRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsProjection;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;

/**
 * The old shape of CounterRow, without a table of its own: `key` was renamed to `businessKey`, `quantity` to
 * `amount`; old clients still filter, sort and select in these names
 */
#[QueryOptionsProjection(targetClass: CounterRow::class, propertyMap: ['key' => 'businessKey', 'quantity' => 'amount'])]
class LegacyCounterRow extends Entity
{
    use QueryOptionsTrait;

    /** @var string|null The old name of businessKey */
    public ?string $key = null;

    /** @var int|null The old name of amount */
    public ?int $quantity = null;

    /** @var bool Same name as on CounterRow */
    public bool $isActive = true;
}
