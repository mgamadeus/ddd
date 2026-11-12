<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass;

use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Entities\ValueObject;

/** A module's class with query options; the application registers {@see ApplicationQueryOptionsProbe} in its place. */
class ModuleQueryOptionsProbe extends ValueObject
{
    use QueryOptionsTrait;

    public ?string $name = null;

    public ?string $summary = null;
}
