<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures;

use DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource\ModuleListenerInterface;

/** An application service implementing a module interface (outside the module's source path) */
class ApplicationModuleListener implements ModuleListenerInterface
{
}
