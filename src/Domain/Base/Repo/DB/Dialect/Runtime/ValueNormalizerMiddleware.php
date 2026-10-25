<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * D8 "Result values" — values only, keys are never renamed: normalizes every fetched row's values so app code
 * written against MariaDB's shapes keeps seeing them on PostgreSQL. Two cases, decided per value:
 * - `is_resource($value)` — a `LARGE_OBJECT`/bytea column PDO pgsql hands back as a stream — turned into the
 *   string pdo_mysql already returns for the same column (`stream_get_contents()`).
 * - `is_bool($value)` — PostgreSQL's native `boolean` columns round-trip as PHP `bool`; MariaDB's `TINYINT(1)`
 *   columns round-trip as `int` (`0`/`1`) — turned into `(int)$value` for parity.
 *
 * Registered only by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}. Bind-value shaping
 * for writes (upsert column policies, batch binding) is a separate, write-path concern (§6 steps 3/4) and is not
 * this middleware's job — this one only ever touches values coming OUT of a result set.
 */
final class ValueNormalizerMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new ValueNormalizerDriver($driver);
    }
}
