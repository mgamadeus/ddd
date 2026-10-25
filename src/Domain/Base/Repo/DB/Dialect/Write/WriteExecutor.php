<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Transaction/savepoint orchestration for PostgreSQL writes (dual-dialect architecture plan §4.6-§4.7). MariaDB
 * needs none of this — {@see \DDD\Domain\Base\Repo\DB\Dialect\Write\MariaDbUpsertBuilder} wraps the existing
 * `DoctrineEntityManager` methods unchanged, which already own their own transaction handling.
 *
 * - {@see self::runSavepointed()}: one DDD write operation inside an ALREADY-open transaction takes exactly one
 *   savepoint, rethrowing the original exception after `ROLLBACK TO` (§4.7); outside a transaction it is a plain
 *   call — autocommit already gives single-statement atomicity, and PostgreSQL savepoints only exist inside a
 *   transaction.
 * - {@see self::withoutWriteSavepoint()}: opts a whole call tree out of the per-operation savepoint — for a
 *   row-by-row loop (repeated-conflict-key batch rows, Mig §3.3) whose OWN caller already wraps it in one
 *   savepoint or transaction, so 200 individual writes don't each take their own (§4.7's "more than 64
 *   savepointed writes … log a warning").
 * - {@see self::runInOwnOrCallerTransaction()}: the post-write rights check (no id + rights query) needs a
 *   revert point reaching back to BEFORE the insert — a savepoint's `ROLLBACK TO` only undoes statements issued
 *   AFTER it was taken, so this always takes its own savepoint/transaction rather than reusing
 *   `runSavepointed()`'s (which would already have committed-in-spirit by the time the rights check runs).
 */
final class WriteExecutor
{
    protected static int $savepointCounter = 0;

    protected static bool $suppressAutoSavepoint = false;

    public static function withoutWriteSavepoint(callable $fn): mixed
    {
        $previous = self::$suppressAutoSavepoint;
        self::$suppressAutoSavepoint = true;
        try {
            return $fn();
        } finally {
            self::$suppressAutoSavepoint = $previous;
        }
    }

    public static function runSavepointed(Connection $connection, callable $fn): mixed
    {
        if (self::$suppressAutoSavepoint || !$connection->isTransactionActive()) {
            return $fn();
        }
        $savepointName = 'DDD_WRITE_' . ++self::$savepointCounter;
        $connection->createSavepoint($savepointName);
        try {
            $result = $fn();
            $connection->releaseSavepoint($savepointName);
            return $result;
        } catch (Throwable $throwable) {
            try {
                $connection->rollbackSavepoint($savepointName);
            } catch (Throwable) {
                // the transaction is broken beyond savepoint reach (e.g. connection loss); the original
                // exception carries the cause, the caller's own rollback / the central desync heal covers it
            }
            throw $throwable;
        }
    }

    /**
     * No open transaction → opens one, commits/rolls back around `$fn`; an already-open transaction → runs
     * `$fn` directly inside it (the caller owns commit/rollback) but FIRST takes a savepoint, so a denial inside
     * `$fn` can be reverted without touching the caller's prior work (mirrors
     * `DoctrineEntityManager::upsert()`'s `DDD_UPSERT_INSERT_RIGHTS_<n>` savepoint on MariaDB).
     */
    public static function runInOwnOrCallerTransaction(Connection $connection, callable $fn): mixed
    {
        if (!$connection->isTransactionActive()) {
            $connection->beginTransaction();
            try {
                $result = $fn();
                $connection->commit();
                return $result;
            } catch (Throwable $throwable) {
                if ($connection->isTransactionActive()) {
                    try {
                        $connection->rollBack();
                    } catch (Throwable) {
                    }
                } else {
                    $connection->close();
                }
                throw $throwable;
            }
        }

        $savepointName = 'DDD_UPSERT_INSERT_RIGHTS_' . ++self::$savepointCounter;
        $connection->createSavepoint($savepointName);
        try {
            $result = $fn();
            $connection->releaseSavepoint($savepointName);
            return $result;
        } catch (Throwable $throwable) {
            if ($connection->isTransactionActive()) {
                try {
                    $connection->rollbackSavepoint($savepointName);
                } catch (Throwable) {
                }
            }
            throw $throwable;
        }
    }
}
