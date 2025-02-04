<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadTrait;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Traits\AfterConstruct\AfterConstructTrait;
use DDD\Infrastructure\Traits\ReflectorTrait;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Infrastructure\Traits\ValidatorTrait;
use DDD\Presentation\Base\OpenApi\Attributes\ClassName;
use ReflectionException;

abstract class DefaultObject extends BaseObject
{
    use SerializerTrait, ValidatorTrait, ParentChildrenTrait, AfterConstructTrait, ParentChildrenTrait, LazyLoadTrait, ReflectorTrait;

    /**
     * @var string|null The fully qualified class name of the object
     */
    #[ClassName]
    public ?string $objectType;

    public function __construct()
    {
        $this->objectType = static::class;
        $this->afterConstruct();
    }

    /**
     * @return static
     */
    public static function newInstance(): static
    {
        $className = DDDService::instance()->getContainerServiceClassNameForClass(static::class);
        return new $className();
    }

    /**
     * Compares Object to other Object and returns true if they are identical in their public properties
     * @param DefaultObject $other
     * @return bool
     */
    public function isEqualTo(?DefaultObject $other = null): bool
    {
        if (!$other) {
            return false;
        }
        if ($this->objectType != $other->objectType) {
            return false;
        }
        $selfJson = $this->toJSON();
        $otherJson = $other->toJSON();
        return $selfJson == $otherJson;
    }


    /**
     * Recursively deep clones this object.
     *
     * @param array<int, object> $callPath A cache of visited objects (mapping spl_object_id to clone).
     *
     * @return static
     * @throws ReflectionException
     */
    public function clone(array &$callPath = []): DefaultObject
    {
        $objectId = spl_object_id($this);

        // If we've already cloned this object, return the clone to avoid infinite recursion.
        if (isset($callPath[$objectId])) {
            return $callPath[$objectId];
        }

        // Properties that should not be cloned by default.
        $ignoredProperties = ['parent' => true];

        // Create a new instance of the current class.
        $clone = new static();

        // Immediately register this clone in the global cache.
        $callPath[$objectId] = $clone;

        // Optionally, copy over the parent, if set.
        $parent = $this->getParent();
        if ($parent !== null) {
            $clone->setParent($parent);
        }

        // Consider both public and protected properties.
        $properties = $this->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);
        foreach ($properties as $property) {
            $propertyName = $property->getName();
            if (isset($ignoredProperties[$propertyName])) {
                continue;
            }
            if (!isset($this->$propertyName)) {
                continue;
            }
            $value = $this->$propertyName;

            // If the value is an array, clone each element.
            if (is_array($value)) {
                $clone->$propertyName = [];
                foreach ($value as $key => $element) {
                    $clone->$propertyName[$key] = $this->deepCloneValue($element, $callPath);
                }
            } else {
                $clone->$propertyName = $this->deepCloneValue($value, $callPath);
            }
        }

        return $clone;
    }

    /**
     * Recursively clones a property value while using the global visited cache.
     *
     * @param mixed $value The property value to clone.
     * @param array<int, object> &$visited The global cache of visited objects.
     * @return mixed
     * @throws ReflectionException
     */
    protected function deepCloneValue(mixed &$value, array &$visited): mixed
    {
        // If it's not an object, just return it.
        if (!is_object($value)) {
            return $value;
        }

        $objectId = spl_object_id($value);

        // If this object has already been cloned, return its clone.
        if (isset($visited[$objectId])) {
            return $visited[$objectId];
        }

        // Otherwise, clone it.
        if ($value instanceof self) {
            // If it's one of our objects, use clone.
            $cloned = $value->clone($visited);
        } else {
            // Otherwise, use PHP's built-in clone.
            $cloned = clone $value;
            // Register non-custom objects as well.
            $visited[$objectId] = $cloned;
        }

        return $cloned;
    }

    /**
     * Overwerites all properties, that are set, from other object and maintains all properties of current object that are not set on other
     *
     * @param DefaultObject|null $other
     * @param array              $callPath
     * @param bool               $cloneProperties
     *
     * @return DefaultObject
     * @throws ReflectionException
     */
    public function overwritePropertiesFromOtherObject(
        ?DefaultObject $other,
        array $callPath = [],
        bool $cloneProperties = false
    ): DefaultObject {
        if (isset($callPath[spl_object_id($this)])) {
            return $this;
        }
        // check identity of objects
        if (!is_a($other, static::class)) {
            return $this;
        }
        $callPath[spl_object_id($this)] = true;
        $propertyNamesToIgnore = ['elements' => true];
        // iterate through all public properties
        foreach ($this->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            if (isset($propertyNamesToIgnore[$propertyName])) {
                // we ignore some properties, e.g. elements from ObjectSets
                continue;
            }
            if (!isset($this->$propertyName) && isset($other->$propertyName)) {
                // if a property is not set but set in $other, we use the one from $other
                $otherProperty = $other->$propertyName;
                if (is_object($otherProperty)) {
                    if ($otherProperty instanceof DefaultObject) {
                        // in case of DefaultObject, we use clone method
                        if ($cloneProperties) {
                            $otherProperty = $otherProperty->clone();
                        }
                    } else {
                        // in default case, we use system clone method
                        $otherProperty = $cloneProperties ? clone $otherProperty : $otherProperty;
                    }
                }
                $this->$propertyName = $otherProperty;
            } elseif (isset($this->$propertyName) && isset($other->$propertyName)) {
                // in case of both $this and $other have property set
                if ($other->$propertyName !== null) {
                    $thisProperty = $this->$propertyName;
                    $otherProperty = $other->$propertyName;
                    if (is_object($otherProperty)) {
                        // in an object context
                        if ($thisProperty instanceof DefaultObject) {
                            // in case of DefaultObject, we overwrite recursively
                            $thisProperty->overwritePropertiesFromOtherObject(
                                $otherProperty,
                                $callPath,
                                $cloneProperties
                            );
                        } else {
                            $this->$propertyName = $cloneProperties ? clone $otherProperty : $otherProperty;
                        }
                    } else {
                        $this->$propertyName = $otherProperty;
                    }
                }
            }
        }
        // handle ObjectSets
        if ($this instanceof ObjectSet) {
            /** @var ObjectSet $other */
            foreach ($other->getElements() as $otherElement) {
                $thisElement = $this->getByUniqueKey($otherElement->uniqueKey());
                if ($otherElement instanceof DefaultObject) {
                    if ($cloneProperties) {
                        $otherElement = $otherElement->clone();
                    }
                } else {
                    $otherElement = $cloneProperties ? clone $otherElement : $otherElement;
                }
                if (!$thisElement) {
                    $this->add($otherElement);
                } elseif (!($thisElement instanceof DefaultObject)) {
                    $this->replace($otherElement);
                } else {
                    $thisElement->overwritePropertiesFromOtherObject($otherElement, $callPath, $cloneProperties);
                }
            }
        }
        return $this;
    }
}