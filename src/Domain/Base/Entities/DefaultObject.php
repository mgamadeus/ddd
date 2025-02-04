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
     * @param array<int, bool> $callPath An array of visited object IDs to prevent infinite recursion.
     * @return static
     * @throws ReflectionException
     */
    public function clone(array $callPath = []): DefaultObject
    {
        if (isset($callPath[spl_object_id($this)])) {
            return $this;
        }

        // Properties that should not be cloned by default.
        $propertyNamesToIgnore = ['parent' => true];

        $clone = new static();

        // Copy over the parent, if set.
        $parent = $this->getParent();
        if ($parent) {
            $clone->setParent($parent);
        }

        // This cache keeps track of which objects have been cloned so far.
        $clonedObjects = [];

        // Consider both public and protected properties.
        foreach ($this->getProperties(ReflectionProperty::IS_PUBLIC|ReflectionProperty::IS_PROTECTED) as $property) {
            $propertyName = $property->getName();
            if (isset($propertyNamesToIgnore[$propertyName])) {
                continue;
            }
            if (!isset($this->$propertyName)) {
                continue;
            }
            $value = $this->$propertyName;
            if (is_array($value)) {
                $clone->$propertyName = [];
                foreach ($value as $arrayIndex => $arrayElement) {
                    $clone->$propertyName[$arrayIndex] = $this->clonePropertyConsideringCache(
                        $clonedObjects,
                        $arrayElement,
                        $callPath,
                    );
                }
            } else {
                $clone->$propertyName = $this->clonePropertyConsideringCache(
                    $clonedObjects,
                    $value,
                    $callPath,
                );
            }
        }

        return $clone;
    }

    /**
     * Recursively clones a property value while using a cache to avoid cloning the same object twice.
     *
     * @param array<int, mixed> &$clonedObjects A cache of objects already cloned (keyed by spl_object_id).
     * @param mixed $value The property value to clone.
     * @param array<int, bool> $visited The array of visited object IDs.
     * @return mixed
     * @throws ReflectionException
     */
    protected function clonePropertyConsideringCache(
        array &$clonedObjects,
        mixed $value,
        array $visited,
    ): mixed {
        // Non-objects are returned as is.
        if (!is_object($value)) {
            return $value;
        }

        $objectId = spl_object_id($value);
        // Return the cached clone if we’ve already cloned this object.
        if (isset($clonedObjects[$objectId])) {
            return $clonedObjects[$objectId];
        }

        // If the value is a DefaultObject (or a subclass) or an object, we clone it
        $cloned = ($value instanceof self) ? $value->clone($visited) : clone $value;
        $clonedObjects[$objectId] = $cloned;
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