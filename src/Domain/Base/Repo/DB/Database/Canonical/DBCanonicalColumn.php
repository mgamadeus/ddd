<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Canonical;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Canonical, comparator-friendly snapshot of one column — either regular or generated.
 *
 * Used by both sides of the schema diff:
 *  - Live side: built by {@see \DDD\Domain\Common\Services\DatabaseSchemaIntrospectionService}
 *    from INFORMATION_SCHEMA.COLUMNS.
 *  - Target side: built by {@see \DDD\Domain\Common\Services\DatabaseSchemaDiffService} from
 *    {@see \DDD\Domain\Base\Repo\DB\Database\DatabaseColumn} (the rich DDD attribute object).
 *
 * Both sides MUST converge on this shape. Adding a field here is a hard-typed reminder to update
 * both builders — the typed constructor and `init` checks prevent the silent "live-vs-target field
 * drift" bugs that array-based canonical structs are prone to.
 *
 * Generated columns (virtual + stored) live in the same type. `isGenerated` discriminates; when
 * true, `generationExpression` and `isStored` are populated and `defaultValue` is meaningless.
 */
class DBCanonicalColumn extends ValueObject
{
    /** @var string Column name. */
    public string $name;

    /** @var string Canonical SQL type — BOOLEAN, INT, BIGINT, VARCHAR, TEXT, JSON, POINT, VECTOR, etc. */
    public string $sqlType;

    /** @var int|null VARCHAR/CHAR length. Null for types where length has no semantic meaning. */
    public ?int $length = null;

    /** @var int|null VECTOR dimension count. Null when not a vector. */
    public ?int $vectorDimensions = null;

    public bool $allowsNull = true;

    /** @var bool Only meaningful for integer types (INT/BIGINT). */
    public bool $isUnsigned = false;

    public bool $hasAutoIncrement = false;

    /**
     * @var string|null Literal default rendered as the string MySQL would store in COLUMN_DEFAULT
     * (no surrounding quotes). Null when no default OR when {@see $defaultIsExpression} is true.
     */
    public ?string $defaultValue = null;

    /**
     * @var bool True when the live side stores an SQL-expression default (CURRENT_TIMESTAMP,
     * UUID(), JSON_OBJECT() …). DDD cannot express expression defaults, so on target side this
     * is always false. The diff comparator skips defaultValue comparison when this is true on
     * the current side, so live expression defaults are preserved rather than silently stripped.
     */
    public bool $defaultIsExpression = false;

    public bool $isGenerated = false;

    /**
     * @var string|null Generation expression as MySQL/MariaDB stores it post-parse (already
     * lowercased on the live side, raw on target). Compared after normalisation via
     * DatabaseSchemaIntrospectionService::normaliseGenerationExpression.
     */
    public ?string $generationExpression = null;

    /** @var bool|null True = STORED, false = VIRTUAL. Null when {@see $isGenerated} is false. */
    public ?bool $isStored = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name);
    }
}
