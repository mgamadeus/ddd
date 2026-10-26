<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One SQL fragment {@see RowNumberPagePagination} renders before assembling the final paginated statement: the SQL
 * text with its `?` placeholders renamed to deterministic named tokens, the table/column alias the assembly step
 * needs from it, and the bind value / bind type for each token.
 */
class PaginationSql extends ValueObject
{
    /**
     * @param string $sql The fragment's SQL, placeholders renamed to `:<tokenPrefix><n>` tokens
     * @param array<string, mixed> $parametersByToken Bind value per named token
     * @param array<string, mixed> $typesByToken Bind type per named token
     * @param string $alias The id page's id column alias, or the main query's root table alias; empty when the
     *     fragment carries none (e.g. straight out of the placeholder allocation step)
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $parametersByToken,
        public readonly array $typesByToken,
        public readonly string $alias = '',
    ) {
        parent::__construct();
    }

    /** Same SQL, same bind maps, with the alias the caller read off the fragment. */
    public function withAlias(string $alias): static
    {
        return new static($this->sql, $this->parametersByToken, $this->typesByToken, $alias);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
