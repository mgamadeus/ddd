<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One issues event on its way to the PSR-3 logger: the message and the log context. The context stays a PSR-3 array —
 * the logger interface takes it in that shape.
 */
class IssuesLogEvent extends ValueObject
{
    public function __construct(
        public string $message,
        /** @var array<string|int, mixed> PSR-3 log context */
        public array $context = [],
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
