<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits;

use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Presentation\Base\OpenApi\Attributes\ClassName;
use ReflectionException;
use ReflectionUnionType;

trait ReflectorTrait
{
    public static function getClassWithNamespace(): ClassWithNamespace
    {
        $reflectionClass = static::getReflectionClass();
        return $reflectionClass->getClassWithNamespace();
    }

    /**
     * returns an reflectionclass for current Class
     * @return ReflectionClass
     * @throws ReflectionException
     */
    public static function getReflectionClass(): ReflectionClass
    {
        return ReflectionClass::instance(static::class);
    }

    /**
     * Returns the instance of the first found class attribute of the given name
     * @param string|null $name
     * @param int $flags
     * @return mixed|object|null
     * @throws ReflectionException
     */
    public static function getAttributeInstance(?string $name = null)
    {
        $reflectionClass = static::getReflectionClass();
        return $reflectionClass->getAttributeInstance($name);
    }

    /**
     * returns property matching the provied Class
     * builtin Types will be ignored here
     * @param string $className
     * @return \ReflectionProperty
     */
    public function getPropertyOfType(string $className): ?\ReflectionProperty
    {
        $properties = $this->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($properties as $property) {
            $type = $property->getType();
            if ($type instanceof ReflectionUnionType) {
                $unionTypes = $type->getTypes();
                foreach ($unionTypes as $unionType) {
                    if ($unionType->isBuiltin()) {
                        continue;
                        $typeName = $unionType->getName();
                        if ($typeName == $className || '\\' . $typeName == $className) {
                            return $property;
                        }
                    }
                }
            } else {
                if ($type->isBuiltin()) {
                    continue;
                }
                $typeName = $type->getName();
                if ($typeName == $className || '\\' . $typeName == $className) {
                    return $property;
                }
            }
        }
        return null;
    }

    /**
     * get all Properties from current entity filtered by a property filter, e.g. \ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PRIVATE
     * @param int|null $filter
     * @param $forSerialization
     * @return ReflectionProperty[]|\ReflectionProperty[]
     * @throws ReflectionException
     */
    public function getProperties(?int $filter = null, bool $forSerialization = false): array
    {
        return $forSerialization ? $this->getReflectionClass()->getPropertiesForSerialization(
            $filter
        ) : $this->getReflectionClass()->getProperties($filter);
    }

    /**
     * Returns true if property exists in current object and is initialized
     * @param string $propertyName
     * @return bool
     */
    public function hasInitializedProp(string $propertyName): bool
    {
        $property = $this->getProperty($propertyName);
        if (!$property) {
            return false;
        }
        return $property->isInitialized($this);
    }

    /**
     * @param string $key
     * @return \ReflectionProperty|null
     */
    protected function getProperty(string $key): \ReflectionProperty|ReflectionProperty|null
    {
        try {
            $property = $this->getReflectionClass()->getProperty($key);
            return $property;
        } catch (ReflectionException $e) {
            return null;
        }
    }

    /**
     * Creates an instance of $className if it is a sibling class of current class and fills it with all
     * values from current class. Also changes parent of children (of ParentChildrenTrait) to new instance
     * @param string $className
     * @return $this
     * @throws ReflectionException
     */
    public function sidecastToClassName(string $className): mixed
    {
        $instance = new $className();
        $instanceReflectionClass = ReflectionClass::instance($className);
        foreach ($this->getProperties(null, true) as $property) {
            $propertyName = $property->getName();
            try {
                $instanceProperty = $instanceReflectionClass->getProperty($propertyName);
            } catch (ReflectionException $e) {
                continue;
            }

            if (isset($this->$propertyName)) {
                $instanceProperty->setAccessible(true);
                // class name attribute means that the value of the property contains the class
                // name so we need to use the new class name
                if ($property->getAttributes(ClassName::class)) {
                    $instanceProperty->setValue($instance, $className);
                } else {
                    $instanceProperty->setValue($instance, $this->$propertyName);
                }
                $instanceProperty->setAccessible(false);
            }
        }
        foreach ($this->getChildren() as $child) {
            $child->setParent($instance);
        }
        return $instance;
    }

    /**
     * Creates an instance of $className if it is a child class of current class and fills it with all
     * values from current class. Also changes parent of children (of ParentChildrenTrait) to new instance
     * @param string $className
     * @return $this
     * @throws ReflectionException
     */
    public function downcastToClassName(string $className): static
    {
        if (!is_subclass_of($className, static::class)) {
            return $this;
        }
        $instance = new $className();
        $instanceReflectionClass = ReflectionClass::instance($className);
        foreach ($this->getProperties(null, true) as $property) {
            $propertyName = $property->getName();
            $instanceProperty = $instanceReflectionClass->getProperty($propertyName);
            if (isset($this->$propertyName)) {
                $instanceProperty->setAccessible(true);
                // class name attribute means that the value of the property contains the class
                // name so we need to use the new class name
                if ($property->getAttributes(ClassName::class)) {
                    $instanceProperty->setValue($instance, $className);
                } else {
                    $instanceProperty->setValue($instance, $this->$propertyName);
                }
                $instanceProperty->setAccessible(false);
            }
        }
        foreach ($this->getChildren() as $child) {
            $child->setParent($instance);
        }
        return $instance;
    }
}