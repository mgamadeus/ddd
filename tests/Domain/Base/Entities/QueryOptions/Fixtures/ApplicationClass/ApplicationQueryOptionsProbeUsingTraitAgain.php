<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass;

use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/** The application's class in place of {@see ModuleQueryOptionsProbeUsedAgain}; it has its own trait storage. */
class ApplicationQueryOptionsProbeUsingTraitAgain extends ModuleQueryOptionsProbeUsedAgain
{
    use QueryOptionsTrait;

    public ?string $agentSpaceKey = null;
}
