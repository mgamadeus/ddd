<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Result of a backend connectivity check.
 */
class IssuesBackendProbe extends ValueObject
{
    public function __construct(
        public readonly string $backend,
        public readonly bool $reachable,
        public readonly string $detail = '',
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
