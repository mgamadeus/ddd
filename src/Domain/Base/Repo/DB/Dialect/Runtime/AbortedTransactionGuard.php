<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * §4.7: a statement error inside a PostgreSQL transaction leaves the whole transaction in the 25P02 "current
 * transaction is aborted" state — every later statement errors the same way until the transaction ends. This
 * middleware tracks that state per connection ({@see AbortedTransactionGuardConnection}): a statement error while
 * a transaction is open marks the connection rollback-only and remembers the first error; `commit()` on a
 * rollback-only connection sends `ROLLBACK` and throws {@see AbortedTransactionException} (instead of
 * PostgreSQL's own silent `COMMIT` -> `ROLLBACK`); a plain `rollBack()` always clears the mark; a raw
 * `ROLLBACK TO SAVEPOINT …` statement that succeeds also clears it (the one piece of savepoint awareness this
 * middleware needs — the savepoint bookkeeping itself, `DDD_WRITE_<n>` naming and the 64-savepoint warning, is
 * `WriteExecutor`'s job, §6 steps 3/4, not built here).
 *
 * Registered only by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}; MariaDB has no
 * equivalent aborted-transaction state and is untouched.
 */
class AbortedTransactionGuard implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new AbortedTransactionGuardDriver($driver);
    }
}
