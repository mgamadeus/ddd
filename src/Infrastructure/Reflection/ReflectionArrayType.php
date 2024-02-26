<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;

class ReflectionArrayType extends \ReflectionNamedType
{
    public static $arrayTypeCache;

    public function __construct(
        private ?ReflectionProperty &$reflectionProperty = null,
        private ?\ReflectionNamedType $reflectionNamedType = null
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
        $arrayTypes = $this->reflectionProperty->getDocCommentInstance()->getPropertyTypes();
        $classDefinedArrayTypes = $this->reflectionProperty->getReflectionClass()->getDocCommentInstance(
        )->getPropertyTypes(
            $this->reflectionProperty->getName()
        );
        $arrayTypes = $classDefinedArrayTypes ? $classDefinedArrayTypes : $arrayTypes;

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
            $propertyClass = $this->reflectionProperty->getReflectionClass(
            )->getClassWithNamespaceConsideringUseStatements($arrayType);
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

    public function getName(): string
    {
        return $this->reflectionNamedType->getName();
    }
}