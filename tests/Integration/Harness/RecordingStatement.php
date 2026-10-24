<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Harness;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/** Collects bound values via bindValue()/bindParam() and records the statement's SQL + final params on execute(). */
final class RecordingStatement extends AbstractStatementMiddleware
{
    /** @var array<int|string, mixed> */
    private array $boundValues = [];

    public function __construct(Statement $wrappedStatement, private readonly string $sql)
    {
        parent::__construct($wrappedStatement);
    }

    public function bindValue($param, $value, $type = ParameterType::STRING)
    {
        $this->boundValues[$param] = $value;
        return parent::bindValue($param, $value, $type);
    }

    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null)
    {
        $this->boundValues[$param] = $variable;
        return parent::bindParam($param, $variable, $type, $length);
    }

    public function execute($params = null): Result
    {
        RecordingMiddleware::record($this->sql, $params ?? $this->boundValues);
        return parent::execute($params);
    }
}
