<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

use function count;

/**
 * PostgreSQL rendering of the `FIELD` DQL name MariaDB already registers via
 * `DoctrineExtensions\Query\Mysql\Field` (architecture plan §4.8: "the existing DQL functions registered by
 * MariaDbDialect" get a PostgreSQL counterpart under the same DQL name; MariaDB's own registration is untouched,
 * so its golden output cannot move). Grammar mirrors the MariaDB class exactly (field + at least one value,
 * comma-separated, open-ended — a DQL array parameter such as `FIELD(id, :ids)` is ONE AST node here; DBAL's list-
 * parameter expansion turns its single bound placeholder into N placeholders in the FINAL sql text, after this
 * class has already rendered it once — see the class note on `CAST(ARRAY[…] AS …)` below). PostgreSQL only;
 * registered by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `FIELD(id, :ids)` (or any list of values)
 * SQL: `COALESCE(array_position(CAST(ARRAY[…] AS bigint[]), id), 0)` — pdo_pgsql binds the (post-expansion)
 * parameters untyped, so `ARRAY[…]` is ambiguous without the explicit cast; DDD ids are `bigint` on PostgreSQL
 * (Mig §3.1), so that is the element type here. A caller needing `FIELD()` over a non-id column would need its own
 * variant — not exercised today.
 */
class PostgresField extends FunctionNode
{
    protected mixed $field = null;

    /** @var mixed[] */
    protected array $values = [];

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->field = $parser->ArithmeticPrimary();

        $lexer = $parser->getLexer();
        while (count($this->values) < 1 || $lexer->lookahead->type !== TokenType::T_CLOSE_PARENTHESIS) {
            $parser->match(TokenType::T_COMMA);
            $this->values[] = $parser->ArithmeticPrimary();
        }

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $fieldSql = $this->field->dispatch($sqlWalker);
        $valuesSql = [];
        foreach ($this->values as $value) {
            $valuesSql[] = $value->dispatch($sqlWalker);
        }
        $arraySql = 'ARRAY[' . implode(', ', $valuesSql) . ']';

        return "COALESCE(array_position(CAST($arraySql AS bigint[]), $fieldSql), 0)";
    }
}
