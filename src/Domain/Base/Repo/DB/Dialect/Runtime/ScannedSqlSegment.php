<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One segment a character-level SQL scanner consumed in a single step ({@see BacktickQuoteSqlRewriter}'s quoted
 * literal / block comment / dollar-quoted body, {@see \DDD\Domain\Base\Repo\DB\Dialect\Schema\SqlScriptLexer}'s
 * `DELIMITER` command): the consumed text and the scan index right after it.
 */
class ScannedSqlSegment extends ValueObject
{
    /**
     * @param string $text The consumed text, verbatim (e.g. a quoted literal including both quote characters, or
     *     the new delimiter token of a `DELIMITER` command)
     * @param int $nextIndex The scan position right after the consumed segment, where the caller continues
     */
    public function __construct(
        public readonly string $text,
        public readonly int $nextIndex,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
