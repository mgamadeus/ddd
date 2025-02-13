<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadTrait;
use DDD\Domain\Base\Entities\Traits\DefaultObjectTrait;
use DDD\Domain\Base\Entities\Traits\EntityTrait;
use DDD\Domain\Base\Entities\Traits\ValueObjectTrait;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Traits\AfterConstruct\AfterConstructTrait;
use DDD\Infrastructure\Traits\ReflectorTrait;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Infrastructure\Traits\ValidatorTrait;
use ReflectionException;

abstract class DefaultObject extends BaseObject
{
    use SerializerTrait, ValidatorTrait, ParentChildrenTrait, AfterConstructTrait, ParentChildrenTrait, LazyLoadTrait, ReflectorTrait, DefaultObjectTrait;

    /**
     * Determines if the class name or object instance has the EntityTrait
     * @param string|object $objectInstanceOrClassName
     * @return bool
     * @throws ReflectionException
     */
    public static function isEntity(string|object|null $objectInstanceOrClassName): bool
    {
        if ($objectInstanceOrClassName === null) {
            return false;
        }
        if (is_object($objectInstanceOrClassName)) {
            $className = $objectInstanceOrClassName::class;
        }

        if (!is_a($objectInstanceOrClassName, self::class, true)) {
            return false;
        }
        $reflectionClass = new ReflectionClass($objectInstanceOrClassName);
        return $reflectionClass->hasTrait(EntityTrait::class);
    }

    /**
     * Determines if the class name or object instance has ValueObjectTrait and NOT the EntityTrait
     * @param string|object $objectInstanceOrClassName
     * @return bool
     * @throws ReflectionException
     */
    public static function isValueObject(string|object|null $objectInstanceOrClassName): bool
    {
        if ($objectInstanceOrClassName === null) {
            return false;
        }
        if (is_object($objectInstanceOrClassName)) {
            $className = $objectInstanceOrClassName::class;
        }

        if (!is_a($objectInstanceOrClassName, self::class, true)) {
            return false;
        }
        $reflectionClass = new ReflectionClass($objectInstanceOrClassName);
        return $reflectionClass->hasTrait(ValueObjectTrait::class) && !$reflectionClass->hasTrait(EntityTrait::class);
    }
}