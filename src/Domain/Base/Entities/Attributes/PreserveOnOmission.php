<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Attributes;

use DDD\Infrastructure\Reflection\ReflectionClass;
use Attribute;
use ReflectionException;
use ReflectionProperty;

/**
 * A write that does not carry this property leaves its stored column untouched.
 *
 * An entity written as a full upsert persists every initialized property, and a property with an inline default
 * (`= 0`, `= true`, `= 'DRAFT'`) is initialized before hydration starts — so a payload that omits the key wrote the
 * default over the stored value. With this attribute:
 *
 * - hydration of an entity that carries an id ({@see \DDD\Domain\Base\Entities\Entity::setPropertiesFromObject()})
 *   unsets the property when the payload has no such key; a key present with null still clears it;
 * - the model generator emits the model property without an inline default, because `upsert()` skips a field only
 *   while the MODEL property is uninitialized. The column keeps its SQL `DEFAULT`, so an INSERT is unchanged.
 *
 * A create (no id) is never pruned: nothing is stored yet that could be lost.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class PreserveOnOmission
{
    use BaseAttributeTrait;

    /**
     * @deprecated Declare #[PreserveOnOmission] on the property instead. An entity constant of this name
     * (`public const array PRESERVED_ON_OMISSION = ['displayOrder', …]`) is still honoured the same way.
     */
    public const string LEGACY_CLASS_CONSTANT = 'PRESERVED_ON_OMISSION';

    /** @var string[][] Preserved property names by entity class */
    protected static array $preservedPropertyNamesByClass = [];

    /**
     * The properties of $className preserved on omission: the attributed ones plus the legacy class constant.
     *
     * @param string $className Entity class
     * @return string[]
     * @throws ReflectionException
     */
    public static function getPreservedPropertyNamesForClass(string $className): array
    {
        if (isset(static::$preservedPropertyNamesByClass[$className])) {
            return static::$preservedPropertyNamesByClass[$className];
        }
        $preservedPropertyNames = [];
        foreach (ReflectionClass::instance($className)->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            if ($reflectionProperty->getAttributes(self::class)) {
                $preservedPropertyNames[] = $reflectionProperty->getName();
            }
        }
        $legacyConstantName = $className . '::' . self::LEGACY_CLASS_CONSTANT;
        if (defined($legacyConstantName)) {
            foreach ((array)constant($legacyConstantName) as $legacyPropertyName) {
                if (is_string($legacyPropertyName)) {
                    $preservedPropertyNames[] = $legacyPropertyName;
                }
            }
        }
        return static::$preservedPropertyNamesByClass[$className] = array_values(array_unique($preservedPropertyNames));
    }

    /**
     * @param string $className Entity class
     * @param string $propertyName Property of that class
     * @return bool Whether the property is preserved on omission
     * @throws ReflectionException
     */
    public static function isPropertyPreservedOnOmission(string $className, string $propertyName): bool
    {
        return in_array($propertyName, static::getPreservedPropertyNamesForClass($className), true);
    }
}
