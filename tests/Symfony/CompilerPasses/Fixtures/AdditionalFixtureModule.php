<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures;

use DDD\Infrastructure\Modules\DDDModule;

/** A module no Composer file lists: a kernel registers it through getAdditionalModuleClasses() */
class AdditionalFixtureModule extends DDDModule
{
    public static function getSourcePath(): string
    {
        return __DIR__ . '/ModuleSource';
    }

    public static function getPublicServiceNamespaces(): array
    {
        return ['DDD\\Tests\\Symfony\\CompilerPasses\\Fixtures\\ModuleSource\\'];
    }

    public static function getLazyServiceNamespaces(): array
    {
        return ['DDD\\Tests\\Symfony\\CompilerPasses\\Fixtures\\ModuleSource\\Lazy\\'];
    }
}
