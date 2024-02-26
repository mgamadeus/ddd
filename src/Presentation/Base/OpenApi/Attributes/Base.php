<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;

#[Attribute(Attribute::TARGET_CLASS)]
class Base extends ValueObject
{
    use BaseAttributeTrait;
}