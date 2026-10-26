<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A joined entity's DQL alias plus the name of its single identifier field — what
 * {@see DefaultOrderTreeWalker} needs to append an `<alias>.<idField> ASC` tie-breaker for one to-many join.
 */
class JoinedAliasIdentifier extends ValueObject
{
    /**
     * @param string $alias The join's DQL identification variable (e.g. `orderItems`)
     * @param string $identifierFieldName The joined entity's single identifier field (e.g. `id`)
     */
    public function __construct(
        public readonly string $alias,
        public readonly string $identifierFieldName,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
