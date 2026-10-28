<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use Error;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Throws the "same bug" in variants: from two different lines of one function (a deploy that moved code), with two
 * different messages, with two SQLSTATEs, and generic engine errors.
 */
class IssuesFixtureThrower
{
    public function runtimeError(int $variant, string $message = 'failed for id 42'): RuntimeException
    {
        try {
            if ($variant === 1) {
                throw new RuntimeException($message);
            }

            // a few lines further down, as after a deploy that moved the code
            throw new RuntimeException($message);
        } catch (RuntimeException $runtimeException) {
            return $runtimeException;
        }
    }

    public function databaseError(string $sqlState): PDOException
    {
        try {
            $pdoException = new PDOException("SQLSTATE[$sqlState]: something");
            $pdoException->errorInfo = [$sqlState, 1, 'something'];
            throw $pdoException;
        } catch (PDOException $caughtPdoException) {
            return $caughtPdoException;
        }
    }

    public function engineError(string $message): Throwable
    {
        try {
            throw new Error($message);
        } catch (Error $error) {
            return $error;
        }
    }
}
