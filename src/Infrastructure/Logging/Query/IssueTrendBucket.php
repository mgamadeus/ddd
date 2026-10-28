<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One bucket of an error trend: its start and the error events in [start, start + step).
 */
class IssueTrendBucket extends ValueObject
{
    public function __construct(
        public readonly DateTime $start,
        public readonly int $count = 0,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)$this->start->getTimestamp());
    }
}
