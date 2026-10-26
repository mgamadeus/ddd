<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * D1/§4.5: raw SQL across DDD core and the apps quotes every identifier and alias with backticks, the one
 * identifier-quoting style that both engines are expected to read today. This middleware rewrites that raw SQL's
 * backticks to PostgreSQL's double quotes right before the driver sees it ({@see BacktickQuoteSqlRewriter} does
 * the actual lexing — outside string literals, comments and `$tag$` bodies). Registered only by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}; MariaDB's raw SQL already matches its
 * own quoting, so this middleware never runs there.
 */
class BacktickQuoteMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new BacktickQuoteDriver($driver);
    }
}
