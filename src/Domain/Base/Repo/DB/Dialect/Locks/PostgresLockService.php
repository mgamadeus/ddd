<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Locks;

use DDD\Domain\Base\Repo\DB\Dialect\DatabaseConfigurationException;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Infrastructure\Libs\Config;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * PostgreSQL side of {@see LockService} (architecture plan §4.12, D3). Deliberately never uses the scope's normal,
 * pooled connection: production PostgreSQL sits behind PgBouncer in transaction mode, where a session-scoped
 * `pg_advisory_lock` would be silently released (or worse, migrate to a different backend mid-hold) the moment the
 * pooler recycles the session between statements. Instead this opens its OWN connection straight to
 * `DB_<SCOPE>_DIRECT_HOST`/`DB_<SCOPE>_DIRECT_PORT` (the CNPG `-rw` service, bypassing the pooler), reusing the
 * scope's normal user/password/dbname. A missing `DB_<SCOPE>_DIRECT_HOST` is refused at boot by
 * `EntityManagerFactory::create()`, before any lock is ever attempted — this class's own check is a defensive
 * second line for a `PostgresLockService` built without going through that boot path (e.g. a unit test).
 *
 * `pg_advisory_lock`'s blocking form is never used — §4.12 explicitly rules it out ("never the store's blocking
 * waitAndSave()") — {@see acquireOnce()} instead polls `pg_try_advisory_lock` with a 50ms→250ms backoff until
 * either it succeeds or $timeoutSeconds elapses, giving PostgreSQL the same "false on timeout" contract MariaDB's
 * `GET_LOCK(name, timeout)` has natively.
 *
 * The direct connection is opened lazily on the first acquisition and closed again once
 * {@see AbstractLockService::closeIfIdle()} reports no name is held any more — a long-lived worker should not pin
 * open a direct, non-pooled connection for its entire lifetime just because it took a lock once (open question Q1
 * of the architecture plan: whether the `-rw` service tolerates this connection churn without a session-mode pool
 * in front of it — unverified by this build step, see this task's open questions).
 */
class PostgresLockService extends AbstractLockService
{
    protected ?Connection $directConnection = null;

    /** The backend PID captured right after connecting — asserted unchanged at every release (§4.12: "pg_backend_pid() asserted equal at lock and unlock"). */
    protected ?int $backendPid = null;

    public function __construct(protected string $scope, protected SqlDialect $dialect)
    {
    }

    protected function acquireOnce(string $name, int $timeoutSeconds): bool
    {
        $connection = $this->connection();
        $key = LockKey::applicationKey($name);
        $deadline = microtime(true) + $timeoutSeconds;
        $delayMs = 50;

        while (true) {
            $acquired = (bool)$connection->fetchOne('SELECT pg_try_advisory_lock(?)', [$key]);
            if ($acquired) {
                return true;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep($delayMs * 1000);
            $delayMs = min($delayMs + 50, 250);
        }
    }

    protected function releaseOnce(string $name): bool
    {
        $connection = $this->connection();
        $currentBackendPid = (int)$connection->fetchOne('SELECT pg_backend_pid()');
        if ($currentBackendPid !== $this->backendPid) {
            throw new LockConnectionLostException(
                "PostgreSQL direct lock connection for scope \"{$this->scope}\" reconnected while lock \"$name\" "
                . "was believed held (backend pid {$this->backendPid} -> $currentBackendPid) — the advisory lock "
                . 'was released by the server when the original backend went away.'
            );
        }

        $key = LockKey::applicationKey($name);
        return (bool)$connection->fetchOne('SELECT pg_advisory_unlock(?)', [$key]);
    }

    protected function closeIfIdle(): void
    {
        if ($this->depths === [] && $this->directConnection !== null) {
            $this->directConnection->close();
            $this->directConnection = null;
            $this->backendPid = null;
        }
    }

    protected function connection(): Connection
    {
        if ($this->directConnection !== null) {
            return $this->directConnection;
        }

        $host = Config::getEnv("DB_{$this->scope}_DIRECT_HOST");
        if (!$host) {
            throw new DatabaseConfigurationException(
                "DB_{$this->scope}_DIRECT_HOST is required for PostgreSQL named locks: LockService takes a "
                . 'dedicated, non-pooled connection, because a session-scoped advisory lock on a PgBouncer '
                . 'transaction-mode connection leaks and loses mutual exclusion (architecture plan §4.12).'
            );
        }
        $port = Config::getEnv("DB_{$this->scope}_DIRECT_PORT") ?: Config::getEnv("DB_{$this->scope}_CONNECTION_HOST_PORT") ?: 5432;

        $params = $this->dialect->connectionParams([
            'driver' => 'pdo_pgsql',
            'host' => (string)$host,
            'port' => (int)$port,
            'user' => Config::getEnv("DB_{$this->scope}_CONNECTION_USER"),
            'password' => Config::getEnv("DB_{$this->scope}_CONNECTION_PASSWORD"),
            'dbname' => Config::getEnv("DB_{$this->scope}_CONNECTION_DB_NAME"),
        ]);

        $connection = DriverManager::getConnection($params);
        $this->backendPid = (int)$connection->fetchOne('SELECT pg_backend_pid()');
        return $this->directConnection = $connection;
    }
}
