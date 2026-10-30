<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** @see ValueNormalizerMiddleware */
class ValueNormalizerConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): Statement
    {
        return new ValueNormalizerStatement(parent::prepare($sql));
    }

    public function query(string $sql): Result
    {
        return new ValueNormalizerResult(parent::query($sql));
    }
}
