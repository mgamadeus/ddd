<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Reflection\ArrayTypes\ModuleSets;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * A set of a module whose elements may be null
 * @property (float|null)[] $elements
 */
class ModuleSetWithNullableElements extends ObjectSet
{
}
