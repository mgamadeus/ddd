<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Criteria of an event search in the backend. Every set criterion must match.
 */
class IssueEventSearch extends ValueObject
{
    public function __construct(
        public readonly DateTime $from,
        public readonly DateTime $to,
        /** @var string|null Case-insensitive text anywhere in the line */
        public readonly ?string $text = null,
        /** @var string[] Levels (lower case); empty = error and above */
        public readonly array $levels = [],
        public readonly ?string $routeName = null,
        public readonly ?string $accountId = null,
        public readonly ?string $groupHash = null,
        public readonly ?string $issueHash = null,
        public readonly int $limit = 20,
        /** @var bool Oldest first instead of newest first */
        public readonly bool $oldestFirst = false,
        /** @var string|null Only lines of this workspace (`service.workspace`) */
        public readonly ?string $workspace = null,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
