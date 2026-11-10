<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** A module extension point: every implementing service, in the module or the application, carries the tag */
#[AutoconfigureTag('fixture.module_listener')]
interface ModuleListenerInterface
{
}
