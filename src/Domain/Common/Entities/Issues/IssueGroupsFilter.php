<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Filter of the registry list: every set criterion must match.
 */
class IssueGroupsFilter extends ValueObject
{
    public function __construct(
        /** @var string|null OPEN, RESOLVED or IGNORED */
        public readonly ?string $status = null,
        /** @var DateTime|null Registered at or after */
        public readonly ?DateTime $newSince = null,
        /** @var string|null First or last seen in this release */
        public readonly ?string $release = null,
        public readonly ?string $section = null,
        public readonly ?string $environment = null,
        public readonly int $limit = 50,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
