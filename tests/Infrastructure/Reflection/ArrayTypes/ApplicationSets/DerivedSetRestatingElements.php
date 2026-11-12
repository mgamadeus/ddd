<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Reflection\ArrayTypes\ApplicationSets;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Tests\Infrastructure\Reflection\ArrayTypes\ModuleSets\ModuleSetWithDeclaredElements;

/**
 * A derived set whose own declaration wins over its parent's
 * @property ValueObject[] $elements
 */
class DerivedSetRestatingElements extends ModuleSetWithDeclaredElements
{
}
