<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits\AfterConstruct\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

#[Attribute(Attribute::TARGET_METHOD)]
/**
 * Calls method with this attribute appled after constructor is executed
 */
class AfterConstruct
{
    use BaseAttributeTrait;

    public static ?array $afterConstructMethods = null;
}