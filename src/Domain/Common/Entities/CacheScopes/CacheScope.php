<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Entities\CacheScopes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\AppService;
use ReflectionClassConstant;
use ReflectionException;

class CacheScope extends ValueObject
{
    /** @var string Loading of FeatureFlags */
    public const ACCOUNT_ROLES = 'ACCOUNT_ROLES';

    /**
     * Returns all cache scopes based on current classes constants
     * @return array
     * @throws ReflectionException
     */
    public static function getCacheScopes()
    {
        $className = AppService::instance()->getContainerServiceClassNameForClass(static::class);
        $reflectionClass = ReflectionClass::instance($className);
        $featureFlagNames = [];
        return array_values($reflectionClass->getConstants(ReflectionClassConstant::IS_PUBLIC));
    }
}