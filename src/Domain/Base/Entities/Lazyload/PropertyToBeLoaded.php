<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Entities\Lazyload;

use DDD\Domain\Base\Entities\ValueObject;

class PropertyToBeLoaded extends ValueObject
{
    public function __construct(public string $className, public string $propertyName)
    {
    }

}