<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\ParameterType;

/**
 * @see ValueNormalizerMiddleware — and on the way in: a NUL byte in a text value is removed. PostgreSQL rejects it in
 * text (22021 "invalid byte sequence for encoding UTF8: 0x00", a 500 for a `\u0000` in a JSON body), MariaDB stored
 * it; binary binds (LARGE_OBJECT, BINARY) keep it.
 */
class ValueNormalizerStatement extends AbstractStatementMiddleware
{
    public function bindValue($param, $value, $type = ParameterType::STRING)
    {
        if (is_string($value) && $type !== ParameterType::LARGE_OBJECT && $type !== ParameterType::BINARY && str_contains($value, "\0")) {
            $value = str_replace("\0", '', $value);
        }
        return parent::bindValue($param, $value, $type);
    }

    public function execute($params = null): Result
    {
        return new ValueNormalizerResult(parent::execute($params));
    }
}
