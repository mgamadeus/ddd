<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** @see BacktickQuoteMiddleware */
final class BacktickQuoteConnection extends AbstractConnectionMiddleware
{
    public function prepare(string $sql): Statement
    {
        return parent::prepare(BacktickQuoteSqlRewriter::rewrite($sql));
    }

    public function query(string $sql): Result
    {
        return parent::query(BacktickQuoteSqlRewriter::rewrite($sql));
    }

    public function exec(string $sql): int
    {
        return parent::exec(BacktickQuoteSqlRewriter::rewrite($sql));
    }
}
