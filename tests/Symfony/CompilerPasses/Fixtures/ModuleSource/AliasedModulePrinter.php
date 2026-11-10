<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** The module's implementation of its port, published as the port's public alias */
#[AsAlias(public: true)]
class AliasedModulePrinter implements ModulePrinterInterface
{
}
