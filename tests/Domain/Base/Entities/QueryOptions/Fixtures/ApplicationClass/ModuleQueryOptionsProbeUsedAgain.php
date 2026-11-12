<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass;

use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Entities\ValueObject;

/** A module's class whose application class uses the trait again ({@see ApplicationQueryOptionsProbeUsingTraitAgain}). */
class ModuleQueryOptionsProbeUsedAgain extends ValueObject
{
    use QueryOptionsTrait;

    public ?string $name = null;
}
