<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;

use DDD\Infrastructure\Exceptions\InternalErrorException;

class ReflectionAllowedTypes
{
    public array $allowedTypes = [];
    public ?array $allowedValues = null;
    public bool $allowsScalar = false;
    public bool $allowsObject = false;
    public bool $isEnum = false;
    public ?string $enumType = null;
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
                $this->processType($type);
            }
        } else {
            $this->processType($propertyType);
        }
    }

    protected function processType(\ReflectionNamedType $type): void
    {
        $typeName = $type->getName();
        if (!$typeName) {
            return;
        }
        $this->allowedTypesCount++;

        if (enum_exists($typeName)) {
            $enumReflection = ReflectionEnum::instance($typeName);
            $this->isEnum = true;
            $this->allowsScalar = true;
            if ($enumReflection->isBacked()) {
                $backingType = (string)$enumReflection->getBackingType();
            } else {
                $backingType = ReflectionClass::STRING;
            }

            $this->allowedTypes[$backingType] = $type;
            $this->allowedValues = $enumReflection->getEnumValues();
            $this->enumType = $typeName;
        }
        else {
            if (!$type->isBuiltin() || $typeName == 'object') {
                $this->allowsObject = true;
            }
            if (isset(ReflectionClass::SCALAR_BASE_TYPES[$typeName])) {
                $this->allowsScalar = true;
            }
            $this->allowedTypes[$typeName] = $type;
        }
    }
}