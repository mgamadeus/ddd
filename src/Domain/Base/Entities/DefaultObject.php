<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

use DDD\Domain\Base\Entities\Lazyload\LazyLoadTrait;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Services\AppService;
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
        $className = AppService::instance()->getContainerServiceClassNameForClass(static::class);
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
     * Clones Object recursively
     * @param array $callPath
     * @return $this
     * @throws ReflectionException
     */
    public function clone(array $callPath = []): DefaultObject
    {
        if (isset($callPath[spl_object_id($this)])) {
            return $this;
        }
        $propertyNamesToIgnore = ['elements' => true];
        /** @var DefaultObject $clone */
        $clone = new (static::class)();
        $parent = $this->getParent();
        if ($parent) {
            $clone->setParent($parent);
        }
        foreach ($this->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            if (isset($propertyNamesToIgnore[$propertyName])) {
                continue;
            }
            if (!isset($this->$propertyName)) {
                continue;
            }
            $thisProperty = $this->$propertyName;
            if ($thisProperty instanceof DefaultObject) {
                $clone->$propertyName = $thisProperty->clone($callPath);
                if ($this?->children?->contains($thisProperty) ?? null) {
                    $clone->addChildren($clone->$propertyName);
                }
            } elseif (is_object($this->$propertyName)) {
                $clone->$propertyName = clone $thisProperty;
            } else {
                $clone->$propertyName = $thisProperty;
            }
        }
        if ($this instanceof ObjectSet) {
            /** @var ObjectSet $clone */
            foreach ($this->getElements() as $element) {
                if ($element instanceof DefaultObject) {
                    $clonedElement = $element->clone($callPath);
                } else {
                    $clonedElement = clone $element;
                }
                $clone->add($clonedElement);
            }
        }
        return $clone;
    }

    /**
     * Overwerites all properties, that are set, from other object and maintains all properties of current object that are not set on other
     * @param DefaultObject|null $other
     * @return void
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