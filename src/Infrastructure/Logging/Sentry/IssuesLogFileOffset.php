<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Sentry;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * How far a forwarder has read one log file: its inode (a new inode = rotated) and the byte offset after the last
 * complete line handled.
 */
class IssuesLogFileOffset extends ValueObject
{
    public function __construct(
        public readonly string $filePath,
        public readonly int $inode,
        public readonly int $offset,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->filePath);
    }
}
