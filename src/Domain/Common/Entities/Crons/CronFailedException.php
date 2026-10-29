<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Infrastructure\Logging\IssuesGroupDiscriminatorInterface;
use RuntimeException;
use Throwable;

/**
 * A cron that failed without a throwable of its own to report — its command exited non-zero, timed out, or is not
 * registered in this application. One issues group per cron and kind of failure.
 */
class CronFailedException extends RuntimeException implements IssuesGroupDiscriminatorInterface
{
    public const string KIND_EXIT_CODE = 'exitCode';
    public const string KIND_TIMEOUT = 'timeout';
    public const string KIND_COMMAND_NOT_REGISTERED = 'commandNotRegistered';

    public function __construct(
        string $message,
        public readonly string $cronName,
        public readonly string $kind,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getIssuesGroupDiscriminator(): string
    {
        return 'cron:' . $this->cronName . '|' . $this->kind;
    }
}
