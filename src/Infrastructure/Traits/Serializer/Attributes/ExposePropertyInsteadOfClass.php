<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits\Serializer\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use ReflectionAttribute;
use DDD\Infrastructure\Reflection\ReflectionClass;

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

    /**
     * @param string $className
     * @return string|null The property the class is serialized as, null when the class has no such attribute
     */
    public static function getPropertyNameToExposeForClass(string $className): ?string
    {
        static $propertyNamesToExposeByClass = [];
        if (array_key_exists($className, $propertyNamesToExposeByClass)) {
            return $propertyNamesToExposeByClass[$className];
        }
        $propertyNameToExpose = null;
        if (class_exists($className)) {
            foreach (ReflectionClass::instance($className)->getAttributes(self::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $propertyNameToExpose = $attribute->newInstance()->propertyNameToExpose;
            }
        }
        return $propertyNamesToExposeByClass[$className] = $propertyNameToExpose;
    }

}