<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use RuntimeException;
use Throwable;

/**
 * §4.7: thrown by {@see AbortedTransactionGuardConnection::commit()} instead of letting PostgreSQL's silent
 * `COMMIT` -> `ROLLBACK` (its documented behaviour once a transaction is in the 25P02 "current transaction is
 * aborted" state) look like a successful commit to the caller.
 */
final class AbortedTransactionException extends RuntimeException
{
    public static function fromFirstError(?Throwable $firstError): self
    {
        return new self(
            'PostgreSQL: commit() was called on a transaction already aborted by an earlier statement error '
            . '(AbortedTransactionGuard, §4.7) — rolled back instead of the silent COMMIT -> ROLLBACK PostgreSQL '
            . 'would otherwise perform; see the previous exception for the statement that aborted it.',
            previous: $firstError
        );
    }
}
