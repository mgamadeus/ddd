<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/** @see AbortedTransactionGuard */
class AbortedTransactionGuardDriver extends AbstractDriverMiddleware
{
    public function connect(
        #[SensitiveParameter]
        array $params
    ) {
        return new AbortedTransactionGuardConnection(parent::connect($params));
    }
}
