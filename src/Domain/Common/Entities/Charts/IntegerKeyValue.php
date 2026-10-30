<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Charts;

/**
 * A key with an integer value (e.g. a count per category).
 */
class IntegerKeyValue extends AbstractKeyValue
{
    public int $value;

    public function __construct(?string $key = null, int $value = 0)
    {
        $this->value = $value;
        parent::__construct($key);
    }
}
