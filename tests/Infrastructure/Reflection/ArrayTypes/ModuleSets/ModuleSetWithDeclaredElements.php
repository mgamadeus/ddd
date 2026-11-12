<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Reflection\ArrayTypes\ModuleSets;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Tests\Infrastructure\Reflection\ArrayTypes\Elements\DeclaredElement;

/**
 * A set of a module that declares its elements by a short name its use statements resolve
 * @property DeclaredElement[] $elements
 */
class ModuleSetWithDeclaredElements extends ObjectSet
{
}
