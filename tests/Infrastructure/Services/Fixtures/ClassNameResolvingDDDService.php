<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Services\Fixtures;

use DDD\Infrastructure\Services\DDDService;

/** Exposes the protected class-name resolution of DDDService */
class ClassNameResolvingDDDService extends DDDService
{
    public static function resolveContainerServiceClassName(string $className): ?string
    {
        return static::getContainerServiceClassNameForClassPrivate($className);
    }
}
