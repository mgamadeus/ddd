<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;

/** @see ValueNormalizerMiddleware */
class ValueNormalizerStatement extends AbstractStatementMiddleware
{
    public function execute($params = null): Result
    {
        return new ValueNormalizerResult(parent::execute($params));
    }
}
