<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures;

use DDD\Infrastructure\Modules\DDDModule;

/** An abstract module base: never registered as a module itself */
abstract class AbstractFixtureModule extends DDDModule
{
    public static function getSourcePath(): string
    {
        return __DIR__ . '/ModuleSource';
    }
}
