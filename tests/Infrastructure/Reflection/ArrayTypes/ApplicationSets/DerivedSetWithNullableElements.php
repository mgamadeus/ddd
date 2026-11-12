<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Reflection\ArrayTypes\ApplicationSets;

use DDD\Tests\Infrastructure\Reflection\ArrayTypes\ModuleSets\ModuleSetWithNullableElements;

/** A set an application derives in its own namespace without restating the elements */
class DerivedSetWithNullableElements extends ModuleSetWithNullableElements
{
}
