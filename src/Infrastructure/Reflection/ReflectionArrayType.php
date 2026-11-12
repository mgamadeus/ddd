<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;

class ReflectionArrayType extends \ReflectionNamedType
{
    public static $arrayTypeCache;

    public function __construct(
        protected ?ReflectionProperty &$reflectionProperty = null,
        protected ?\ReflectionNamedType $reflectionNamedType = null
    ) {
    }

    public function allowsNull(): bool
    {
        return $this->reflectionNamedType->allowsNull();
    }

    public function isBuiltin(): bool
    {
        return $this->reflectionNamedType->isBuiltin();
    }

    /**
     * Returns the type of an array based on doc comment evaluation
     * @return ReflectionNamedType|ReflectionUnionType|null
     */
    public function getArrayType(): ReflectionNamedType|ReflectionUnionType|null
    {
        $key = $this->reflectionProperty->getReflectionClass()->getName() . '_' . $this->reflectionProperty->getName();
        if (isset(self::$arrayTypeCache[$key])) {
            return self::$arrayTypeCache[$key];
        }
        // a class doc comment's (at)property wins over the property's own (at)var; each is resolved against the use
        // statements and namespace of the class that declares it, not of the class being reflected: a set an application
        // derives from a module set in another namespace inherits the module's declaration
        $classDeclaringArrayTypes = $this->getClassDeclaringArrayTypesInItsDocComment();
        if ($classDeclaringArrayTypes) {
            $arrayTypes = $classDeclaringArrayTypes->getDocCommentInstance()->getPropertyTypes($this->reflectionProperty->getName());
            $classResolvingArrayTypes = $classDeclaringArrayTypes;
        } else {
            $arrayTypes = $this->reflectionProperty->getDocCommentInstance()->getPropertyTypes();
            $classResolvingArrayTypes = ReflectionClass::instance($this->reflectionProperty->getDeclaringClass()->getName());
        }

        /** @var ReflectionNamedType[] $finalTypes */
        $finalTypes = [];
        if (!$arrayTypes) {
            self::$arrayTypeCache[$key] = null;
            return null;
        }
        foreach ($arrayTypes as $arrayType) {
            if ($arrayType == 'null') {
                continue;
            }
            if (in_array($arrayType, ReflectionClass::BASE_TYPES)) {
                $finalTypes[] = new ReflectionNamedType($arrayType, true, true);
                continue;
            }
            $propertyClass = $classResolvingArrayTypes->getClassWithNamespaceConsideringUseStatements($arrayType);
            $classWiithNamespace = $propertyClass->getNameWithNamespace();
            if ($classWiithNamespace) {
                $finalTypes[] = new ReflectionNamedType($classWiithNamespace, false, true);
            }
        }
        if (!count($finalTypes)) {
            self::$arrayTypeCache[$key] = null;
            return null;
        }
        if (count($finalTypes) > 1) {
            $return = new ReflectionUnionType(...$finalTypes);
            self::$arrayTypeCache[$key] = $return;
            return $return;
        }
        self::$arrayTypeCache[$key] = $finalTypes[0];
        return $finalTypes[0];
    }

    /**
     * @return bool true when the doc comment declares null as an element of the array: `(float|null)[]`
     */
    public function allowsNullElements(): bool
    {
        $classDeclaringArrayTypes = $this->getClassDeclaringArrayTypesInItsDocComment();
        if ($classDeclaringArrayTypes) {
            return $classDeclaringArrayTypes->getDocCommentInstance()->allowsNullArrayElements(
                $this->reflectionProperty->getName()
            );
        }
        return $this->reflectionProperty->getDocCommentInstance()->allowsNullArrayElements();
    }

    /**
     * The class whose doc comment declares the property's array type with (at)property: the reflected class or one of
     * its parents up to the class that declares the property, the nearest one first.
     *
     * @return ReflectionClass|null null when no class of that chain declares it
     */
    public function getClassDeclaringArrayTypesInItsDocComment(): ?ReflectionClass
    {
        $propertyName = $this->reflectionProperty->getName();
        $nameOfClassDeclaringProperty = $this->reflectionProperty->getDeclaringClass()->getName();
        $reflectionClass = $this->reflectionProperty->getReflectionClass();
        while ($reflectionClass) {
            if ($reflectionClass->getDocCommentInstance()->getPropertyTypes($propertyName)) {
                return $reflectionClass;
            }
            if ($reflectionClass->getName() === $nameOfClassDeclaringProperty) {
                return null;
            }
            $reflectionClass = $reflectionClass->getParentClass();
        }
        return null;
    }

    public function getName(): string
    {
        return $this->reflectionNamedType->getName();
    }
}