<?php

declare (strict_types=1);

namespace DDD\Infrastructure\Validation\Constraints;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
class NoValidation
{
    use BaseAttributeTrait;
}