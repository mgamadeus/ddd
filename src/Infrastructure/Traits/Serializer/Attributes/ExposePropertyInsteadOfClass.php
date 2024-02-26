<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits\Serializer\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

/**
 * Exposes a property instead the class itself, e.g. a class names Users with a property users as array exposes the array directly
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ExposePropertyInsteadOfClass extends SerializerAttribute
{
    use BaseAttributeTrait;

    public string $propertyNameToExpose = '';

    public function __construct(string $propertyNameToExpose)
    {
        $this->propertyNameToExpose = $propertyNameToExpose;
    }

}