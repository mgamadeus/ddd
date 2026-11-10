<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\ObjectStorage\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/** Keeps every log message with its level */
class RecordingLogger extends AbstractLogger
{
    /** @var string[] "<level> <message>" in the order they were logged */
    public array $loggedLines = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->loggedLines[] = $level . ' ' . $message;
    }
}
