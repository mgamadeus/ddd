<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;

use DDD\Infrastructure\Exceptions\InternalErrorException;

class ReflectionAllowedTypes
{
    public array $allowedTypes = [];
    public bool $allowsScalar = false;
    public bool $allowsObject = false;
    public bool $isArrayType = false;
    public bool $allowsNull = false;
    public int $allowedTypesCount = 0;

    public function __construct(\ReflectionProperty|ReflectionProperty &$property)
    {
        $propertyType = $property->getType();
        if (!$propertyType) {
            throw new InternalErrorException($property->getName() . ' has no Type definition');
        }
        $this->allowsNull = $propertyType->allowsNull();
        if ($propertyType instanceof ReflectionArrayType) {
            $this->isArrayType = true;
            $propertyType = $propertyType->getArrayType();
        }

        if ($propertyType instanceof ReflectionUnionType || $propertyType instanceof \ReflectionUnionType) {
            foreach ($propertyType->getTypes() as $type) {
                $typeName = $type->getName();
                if (!$type->isBuiltin()) {
                    $this->allowsObject = true;
                }
                if ($typeName) {
                    if (isset(ReflectionClass::SCALAR_BASE_TYPES[$typeName])) {
                        $this->allowsScalar = true;
                    }
                    $this->allowedTypes[$typeName] = $type;
                    $this->allowedTypesCount++;
                }
            }
        } else {
            $this->allowedTypesCount++;
            $typeName = $propertyType->getName();
            $this->allowedTypes[$typeName] = $propertyType;
            if (!$propertyType->isBuiltin()) {
                $this->allowsObject = true;
            }
            if (isset(ReflectionClass::SCALAR_BASE_TYPES[$typeName])) {
                $this->allowsScalar = true;
            }
            $this->allowedTypes[$typeName] = $propertyType;
        }
    }
}