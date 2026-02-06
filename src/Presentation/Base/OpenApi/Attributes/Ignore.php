<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

/**
 * If atached, action or entire controller is ignored
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class Ignore extends Base
{
    use BaseAttributeTrait;
}