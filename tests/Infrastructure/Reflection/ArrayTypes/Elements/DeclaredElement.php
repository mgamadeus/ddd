<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Reflection\ArrayTypes\Elements;

use DDD\Domain\Base\Entities\ValueObject;

/** The element a module set declares for its elements */
class DeclaredElement extends ValueObject
{
    public ?string $name = null;
}
