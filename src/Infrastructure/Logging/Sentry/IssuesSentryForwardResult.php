<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Sentry;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one forwarding run did.
 */
class IssuesSentryForwardResult extends ValueObject
{
    /** Files read */
    public int $filesRead = 0;

    /** Events Sentry accepted */
    public int $eventsSent = 0;

    /** Events Sentry refused for good (dropped) */
    public int $eventsRejected = 0;

    /** Lines below the level threshold or not JSON */
    public int $linesSkipped = 0;

    /** True when Sentry asked to slow down or was not reachable: the rest follows on the next run */
    public bool $stoppedForRetry = false;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
