<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

/** Returns parameter as enum with class name as only number */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ClassName extends Base
{
    use BaseAttributeTrait;
}