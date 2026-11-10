<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** A module service tagged by an attribute — what #[AsMessageHandler] / #[AsCommand] are for handlers and commands */
#[AutoconfigureTag('fixture.module_tag')]
class AttributeTaggedModuleService
{
}
