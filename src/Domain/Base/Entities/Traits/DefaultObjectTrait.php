<?php

namespace DDD\Domain\Base\Entities\Traits;

use DDD\Domain\Base\Entities\BaseObject;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Base\OpenApi\Attributes\ClassName;
use ReflectionException;

trait DefaultObjectTrait
{
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
     * Determines if the class name or object instance has the EntityTrait
     * @param string|object $objectInstanceOrClassName
     * @return bool
     * @throws ReflectionException
     */
    public static function isEntity(mixed $objectInstanceOrClassName): bool
    {
        if (!(is_object($objectInstanceOrClassName) || is_string($objectInstanceOrClassName))) {
            return false;
        }
        $class = is_object($objectInstanceOrClassName) ? $objectInstanceOrClassName::class : $objectInstanceOrClassName;
        if (defined("{$class}::IS_ENTITY")) {
            return $class::IS_ENTITY;
        }
        return false;
    }

    /**
     * Determines if the class name or object instance has ValueObjectTrait and NOT the EntityTrait
     * @param string|object $objectInstanceOrClassName
     * @return bool
     * @throws ReflectionException
     */
    public static function isValueObject(mixed $objectInstanceOrClassName): bool
    {
        if (!(is_object($objectInstanceOrClassName) || is_string($objectInstanceOrClassName))) {
            return false;
        }
        $class = is_object($objectInstanceOrClassName) ? $objectInstanceOrClassName::class : $objectInstanceOrClassName;
        if (defined("{$class}::IS_VALUE_OBJECT") && !defined("{$class}::IS_ENTITY")) {
            return $class::IS_VALUE_OBJECT;
        }
        return false;
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

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic(spl_object_id($this));
    }

    /**
     * Verifies Identity by uniqueKey
     * @param BaseObject $other
     * @return bool
     */
    public function equals(BaseObject &$other): bool
    {
        $thisClass = static::class;
        $otherClass = $other::class;

        // Use is_a with allow_string=true to check for same class or inheritance relationships.
        if (!(is_a($thisClass, $otherClass, true) || is_a($otherClass, $thisClass, true))) {
            return false;
        }
        return $this->uniqueKey() == $other->uniqueKey();
    }

    /**
     * Clones Object recursively
     * @param array $callPath
     * @return $this
     * @throws ReflectionException
     */
    public function clone(array &$clonedObjectCache = []): DefaultObject
    {
        if (isset($clonedObjectCache[spl_object_id($this)])) {
            return $clonedObjectCache[spl_object_id($this)];
        }
        $propertyNamesToIgnore = ['parent' => true];
        /** @var DefaultObject $clone */
        $clone = new (static::class)();
        if (empty($clonedObjectCache) && $this->getParent()) {
            $clone->setParent($this->parent);
        }
        $clonedObjectCache[spl_object_id($this)] = $clone;

        foreach ($this->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $property) {
            $propertyName = $property->getName();
            if (isset($propertyNamesToIgnore[$propertyName])) {
                continue;
            }
            if (!isset($this->$propertyName)) {
                continue;
            }
            $propertyValue = $this->$propertyName;

            if (is_array($propertyValue)) {
                $clone->$propertyName = [];
                foreach ($propertyValue as $arrayIndex => $arrayValue) {
                    $clonedArrayValue = null;
                    if (is_object($arrayValue)) {
                        // if element is already cloned, we use the already created clone
                        $objectId = spl_object_id($arrayValue);
                        if (isset($clonedObjectCache[$objectId])) {
                            $clonedArrayValue = $clonedObjectCache[$objectId];
                        } else {
                            if ($arrayValue instanceof self) {
                                $clonedArrayValue = $arrayValue->clone($clonedObjectCache);
                                // if there is a parent - child relationship between $this and $arrayValue
                                // we create it as well between the $clone and $clonedArrayValue
                                if ($arrayValue->getParent() === $this) {
                                    $clonedArrayValue->setParent($clone);
                                }
                            } else {
                                $clonedArrayValue = clone $arrayValue;
                            }
                            $clonedObjectCache[$objectId] = $clonedArrayValue;
                        }
                    } else {
                        $clonedArrayValue = $arrayValue;
                    }
                    $clone->$propertyName[$arrayIndex] = $clonedArrayValue;
                }
            } elseif (is_object($propertyValue)) {
                $objectId = spl_object_id($propertyValue);
                if (isset($clonedObjectCache[$objectId])) {
                    $clonedPropertyValue = $clonedObjectCache[$objectId];
                } else {
                    if ($propertyValue instanceof self) {
                        $clonedPropertyValue = $propertyValue->clone($clonedObjectCache);
                        // if there is a parent - child relationship between $this and $propertyValue
                        // we create it as well between the $clone and $clonedPropertyValue
                        if ($propertyValue->getParent() === $this) {
                            $clonedPropertyValue->setParent($clone);
                        }
                    } else {
                        $clonedPropertyValue = clone $propertyValue;
                    }
                    $clonedObjectCache[$objectId] = $clonedPropertyValue;
                }
                $clone->$propertyName = $clonedPropertyValue;
            } else { // any other non object or array value
                $clone->$propertyName = $propertyValue;
            }
        }
        return $clone;
    }

    /**
     * Overwerites all properties, that are set, from other object and maintains all properties of current object that are not set on other
     *
     * @param DefaultObject|null $other
     * @param array $callPath
     * @param bool $cloneProperties
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