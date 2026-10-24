<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Harness;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/** Wraps the connection it establishes so every statement on it goes through {@see RecordingConnection}. */
final class RecordingDriver extends AbstractDriverMiddleware
{
    public function connect(array $params): DriverConnection
    {
        return new RecordingConnection(parent::connect($params));
    }
}
