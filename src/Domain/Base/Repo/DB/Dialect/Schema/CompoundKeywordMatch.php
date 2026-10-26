<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Schema;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A compound-statement keyword {@see SqlScriptLexer} matched (`BEGIN`, `IF`, `END IF`, …) and how it changes the
 * `BEGIN…END` nesting depth that decides whether the active delimiter is a real statement boundary.
 */
class CompoundKeywordMatch extends ValueObject
{
    /**
     * @param string $keywordText The consumed keyword text, verbatim (e.g. `END IF` consumed as one token)
     * @param int $depthChange +1 for a block opener, -1 for a closer, 0 for an `IF(` call or `IF [NOT] EXISTS`
     */
    public function __construct(
        public readonly string $keywordText,
        public readonly int $depthChange,
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
