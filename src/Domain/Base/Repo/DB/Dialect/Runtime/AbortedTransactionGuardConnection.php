<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Throwable;

/** @see AbortedTransactionGuard */
final class AbortedTransactionGuardConnection extends AbstractConnectionMiddleware
{
    protected bool $inTransaction = false;
    protected bool $rollbackOnly = false;
    protected ?Throwable $firstError = null;

    public function beginTransaction()
    {
        $result = parent::beginTransaction();
        $this->inTransaction = true;
        $this->rollbackOnly = false;
        $this->firstError = null;

        return $result;
    }

    public function commit()
    {
        if ($this->rollbackOnly) {
            $firstError = $this->firstError;
            $this->resetTransactionState();
            // PostgreSQL itself would silently turn this COMMIT into a ROLLBACK (25P02); make that explicit and
            // surface it to the caller instead (§4.7).
            parent::rollBack();
            throw AbortedTransactionException::fromFirstError($firstError);
        }

        $result = parent::commit();
        $this->resetTransactionState();

        return $result;
    }

    public function rollBack()
    {
        $result = parent::rollBack();
        $this->resetTransactionState();

        return $result;
    }

    public function prepare(string $sql): Statement
    {
        return new AbortedTransactionGuardStatement(parent::prepare($sql), $this, $sql);
    }

    public function query(string $sql): Result
    {
        try {
            $result = parent::query($sql);
        } catch (Throwable $error) {
            $this->reportStatementOutcome($sql, $error);
            throw $error;
        }
        $this->reportStatementOutcome($sql, null);

        return $result;
    }

    public function exec(string $sql): int
    {
        try {
            $result = parent::exec($sql);
        } catch (Throwable $error) {
            $this->reportStatementOutcome($sql, $error);
            throw $error;
        }
        $this->reportStatementOutcome($sql, null);

        return $result;
    }

    /**
     * Called by {@see AbortedTransactionGuardStatement::execute()} and this connection's own query()/exec(): a
     * failed statement while a transaction is open marks the connection rollback-only (the first error wins); a
     * successful `ROLLBACK TO SAVEPOINT …` clears an existing mark, since that is exactly PostgreSQL's own escape
     * from the aborted state.
     */
    public function reportStatementOutcome(string $sql, ?Throwable $error): void
    {
        if ($error !== null) {
            if ($this->inTransaction && !$this->rollbackOnly) {
                $this->rollbackOnly = true;
                $this->firstError = $error;
            }

            return;
        }

        if ($this->rollbackOnly && self::isRollbackToSavepoint($sql)) {
            $this->rollbackOnly = false;
            $this->firstError = null;
        }
    }

    protected function resetTransactionState(): void
    {
        $this->inTransaction = false;
        $this->rollbackOnly = false;
        $this->firstError = null;
    }

    protected static function isRollbackToSavepoint(string $sql): bool
    {
        return preg_match('/^\s*ROLLBACK\s+TO\s+(SAVEPOINT\s+)?\S/i', $sql) === 1;
    }
}
