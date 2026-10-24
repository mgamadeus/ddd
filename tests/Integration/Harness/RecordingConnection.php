<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Harness;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** Wraps every prepared statement in {@see RecordingStatement}; records direct query()/exec() SQL immediately. */
final class RecordingConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): Statement
    {
        return new RecordingStatement(parent::prepare($sql), $sql);
    }

    public function query(string $sql): Result
    {
        RecordingMiddleware::record($sql, []);
        return parent::query($sql);
    }

    public function exec(string $sql): int
    {
        RecordingMiddleware::record($sql, []);
        return parent::exec($sql);
    }
}
