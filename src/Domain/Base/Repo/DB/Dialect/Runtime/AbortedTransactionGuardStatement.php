<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Throwable;

/** @see AbortedTransactionGuard */
final class AbortedTransactionGuardStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $wrappedStatement,
        protected readonly AbortedTransactionGuardConnection $connection,
        protected readonly string $sql,
    ) {
        parent::__construct($wrappedStatement);
    }

    public function execute($params = null): Result
    {
        try {
            $result = parent::execute($params);
        } catch (Throwable $error) {
            $this->connection->reportStatementOutcome($this->sql, $error);
            throw $error;
        }
        $this->connection->reportStatementOutcome($this->sql, null);

        return $result;
    }
}
