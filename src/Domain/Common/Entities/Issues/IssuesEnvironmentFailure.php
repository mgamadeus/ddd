<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One environment whose log backend failed while a command read several (a timeout, an unreachable backend); the
 * others still answered.
 */
class IssuesEnvironmentFailure extends ValueObject
{
    /** @var string The registry environment, e.g. `prod` */
    public string $environment = '';

    /** @var string The backend's error, e.g. `Loki query failed: cURL error 28 …` */
    public string $error = '';

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->environment);
    }
}
