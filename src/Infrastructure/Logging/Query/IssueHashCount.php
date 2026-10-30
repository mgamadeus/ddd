<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Number of error events of one (group hash, issue hash, entry point) in a time range — aggregated by the backend.
 */
class IssueHashCount extends ValueObject
{
    public function __construct(
        public readonly string $groupHash,
        public readonly string $issueHash = '',
        public readonly string $entryPoint = '',
        public readonly int $count = 0,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->groupHash . '|' . $this->issueHash . '|' . $this->entryPoint);
    }
}
