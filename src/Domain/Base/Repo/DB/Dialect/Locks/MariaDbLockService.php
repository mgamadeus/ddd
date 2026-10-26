<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Locks;

use Doctrine\DBAL\Connection;

/**
 * MariaDB side of {@see LockService} (architecture plan §4.12): `GET_LOCK`/`RELEASE_LOCK` on the caller's own
 * connection, byte-identical to what called these functions directly before this class existed — this is a pure
 * move behind the facade, not a behaviour change.
 */
class MariaDbLockService extends AbstractLockService
{
    public function __construct(protected Connection $connection)
    {
    }

    protected function acquireOnce(string $name, int $timeoutSeconds): bool
    {
        return (int)$this->connection->fetchOne('SELECT GET_LOCK(?, ?)', [$name, $timeoutSeconds]) === 1;
    }

    protected function releaseOnce(string $name): bool
    {
        return (int)$this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]) === 1;
    }
}
