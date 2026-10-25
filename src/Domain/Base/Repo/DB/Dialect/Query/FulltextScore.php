<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * D14/§4.8 ("Score ordering"): the PostgreSQL rendering behind QueryOptions `{prop}Score` ordering
 * (built by {@see \DDD\Domain\Base\Entities\QueryOptions\OrderByOptions}, mirroring
 * {@see \DDD\Domain\Base\Entities\QueryOptions\FiltersOptions}'s dialect branch for `ft`/`fb`). MariaDB keeps
 * `MATCH … AGAINST` as the HIDDEN score select item (golden); this DQL name is never registered on MariaDB.
 * PostgreSQL only; registered by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `FULLTEXT_SCORE(col, term, 'NAME'|'DOCUMENT')`
 * SQL: `NAME` -> `similarity(ddd_fold(col), ddd_fold(term))`;
 *      `DOCUMENT` -> `ts_rank(to_tsvector('simple', ddd_unaccent(col)), websearch_to_tsquery('simple', ddd_unaccent(term)))`
 *
 * Values are not comparable to MariaDB's `MATCH` relevance score; ordering semantics are (D14, Arch §4.8).
 */
class FulltextScore extends FunctionNode
{
    protected mixed $column;

    protected mixed $term;

    protected string $mode;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->column = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->term = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->mode = (string)($parser->StringPrimary()->value ?? '');
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $columnSql = $this->column->dispatch($sqlWalker);
        $termSql = $this->term->dispatch($sqlWalker);

        return match ($this->mode) {
            'NAME' => "similarity(ddd_fold($columnSql), ddd_fold($termSql))",
            'DOCUMENT' => "ts_rank(to_tsvector('simple', ddd_unaccent($columnSql)), "
                . "websearch_to_tsquery('simple', ddd_unaccent($termSql)))",
            default => throw new InternalErrorException(
                "FULLTEXT_SCORE: unknown fullTextMode \"$this->mode\" (expected NAME|DOCUMENT)."
            ),
        };
    }
}
