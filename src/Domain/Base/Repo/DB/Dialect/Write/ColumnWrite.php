<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One column's write (dual-dialect architecture plan §4.2 `Write/`): the value to bind, its DBAL type, the bind
 * expression it needs ({@see BindExpressionKind}) and the ON CONFLICT / UPDATE form it takes
 * ({@see UpdatePolicy}). Built once per column per row by {@see PostgresUpsertBuilder} from the same
 * `ClassMetadata` + reflection + `DatabaseColumn` attribute walk
 * {@see \DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager::upsert()} already does for MariaDB — same
 * source of truth, two renderers.
 */
class ColumnWrite extends ValueObject
{
    public function __construct(
        public readonly string $columnName,
        public readonly mixed $value,
        public readonly string $dbalType,
        public readonly BindExpressionKind $kind = BindExpressionKind::PLAIN,
        public readonly ?int $vectorDimensions = null,
        public readonly UpdatePolicy $updatePolicy = UpdatePolicy::REPLACE,
        public readonly ?string $rawOnUpdateAction = null,
        public readonly bool $isIdentifier = false,
    ) {
        parent::__construct();
    }

    /** Every instance is its own element: identical values may legitimately repeat, and insertion order matters. */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
