<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

use RuntimeException;

/** A scope's DB_* configuration cannot be used on the resolved dialect (D4.4: e.g. `utf8mb4` charset on pgsql). */
class DatabaseConfigurationException extends RuntimeException
{
}
