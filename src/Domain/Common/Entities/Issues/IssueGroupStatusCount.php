<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * How many groups of one environment have one status — a tab badge of the admin list.
 */
class IssueGroupStatusCount extends ValueObject
{
    public function __construct(
        public readonly ?string $environment,
        public readonly string $status,
        public readonly int $count,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic(($this->environment ?? '') . '|' . $this->status);
    }
}
