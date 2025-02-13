<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

use DDD\Domain\Base\Entities\Traits\ValueObjectTrait;

class ValueObject extends DefaultObject
{
    use ValueObjectTrait;
}