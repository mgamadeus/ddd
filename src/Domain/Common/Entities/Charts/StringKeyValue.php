<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Charts;

/**
 * A key with a string value.
 */
class StringKeyValue extends AbstractKeyValue
{
    public string $value;

    public function __construct(?string $key = null, string $value = '')
    {
        $this->value = $value;
        parent::__construct($key);
    }
}
