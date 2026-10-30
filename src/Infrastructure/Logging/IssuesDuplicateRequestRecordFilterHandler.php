<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Infrastructure\Services\IssuesLogService;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Drops Symfony's own "Uncaught PHP Exception" record (channel `request`) for an exception the issues log already has,
 * so an uncaught error is logged once, not twice. Registered by the DDD bundle as the first handler of the `request`
 * channel: for such a record it stops the handler stack, every other record passes untouched.
 */
class IssuesDuplicateRequestRecordFilterHandler extends AbstractHandler
{
    public function __construct()
    {
        parent::__construct(Level::Debug, false);
    }

    public function isHandling(LogRecord $record): bool
    {
        $exception = $record->context['exception'] ?? null;
        return $exception instanceof Throwable && IssuesLogService::wasLogged($exception);
    }

    public function handle(LogRecord $record): bool
    {
        return $this->isHandling($record);
    }
}
