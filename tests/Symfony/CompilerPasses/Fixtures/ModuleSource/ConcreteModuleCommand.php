<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource;

use Symfony\Component\Console\Attribute\AsCommand;

/** A console command of a module, built on an abstract base */
#[AsCommand(name: 'app:module-fixture:run')]
class ConcreteModuleCommand extends AbstractModuleCommand
{
}
