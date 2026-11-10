<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures;

use DDD\Infrastructure\Modules\DDDModule;

/** A module whose source holds an abstract command base and a command */
class CommandsFixtureModule extends DDDModule
{
    public static function getSourcePath(): string
    {
        return __DIR__ . '/ModuleSource';
    }
}
