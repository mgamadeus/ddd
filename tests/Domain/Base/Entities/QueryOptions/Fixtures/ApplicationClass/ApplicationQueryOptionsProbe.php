<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass;

/** The application's class in place of {@see ModuleQueryOptionsProbe}; it does not use the trait again. */
class ApplicationQueryOptionsProbe extends ModuleQueryOptionsProbe
{
    public ?string $agentSpaceKey = null;
}
