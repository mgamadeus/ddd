<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/** @see BacktickQuoteMiddleware */
class BacktickQuoteDriver extends AbstractDriverMiddleware
{
    public function connect(
        #[SensitiveParameter]
        array $params
    ) {
        return new BacktickQuoteConnection(parent::connect($params));
    }
}
