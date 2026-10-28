<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * D14 / Mig §3.5: the PostgreSQL predicate behind the `ft`/`fb` QueryOptions operators (built by
 * {@see \DDD\Domain\Base\Entities\QueryOptions\FiltersOptions}, which knows the property's declared
 * `fullTextMode` and builds this DQL call instead of MariaDB's `MATCH(col) AGAINST (?)` literal). MariaDB keeps
 * `MATCH … AGAINST` unchanged (golden); this DQL name is never registered on MariaDB. PostgreSQL only; registered
 * by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `FULLTEXT_MATCH(col, term, 'NAME'|'DOCUMENT')` — `mode` is a string literal, known at DQL-build time from
 * the entity's `#[Translatable(fullTextMode:)]` / `#[DatabaseIndex(TYPE_FULLTEXT, fullTextMode:)]` declaration.
 *
 * - `NAME`: every whitespace-separated term of `term` (after stripping `+ - * " ~`) must match as a word-prefix of
 *   `col`'s folded text — `f LIKE t || '%' OR f LIKE '% ' || t || '%'`, ANDed over all terms (Mig §3.5). Expressed
 *   as a `NOT EXISTS` over the failing terms so the whole predicate is one self-contained SQL expression (no
 *   separate per-term parameter list — the query-side term split runs IN SQL via `regexp_split_to_array`).
 * - `DOCUMENT`: `to_tsvector('simple', ddd_unaccent(col)) @@ websearch_to_tsquery('simple', ddd_unaccent(term))`.
 *
 * Both forms call `ddd_fold`/`ddd_unaccent` (ensure()-declared, §6 step 4a) and read a GIN index on the matching
 * expression ({@see \DDD\Domain\Base\Repo\DB\Dialect\Schema\PostgresDdlRenderer::renderFulltextIndex()}).
 */
class FulltextMatch extends FunctionNode
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
            'NAME' => $this->renderNameMode($columnSql, $termSql),
            'DOCUMENT' => "(to_tsvector('simple', ddd_unaccent($columnSql)) "
                . "@@ websearch_to_tsquery('simple', ddd_unaccent($termSql)))",
            default => throw new InternalErrorException(
                "FULLTEXT_MATCH: unknown fullTextMode \"$this->mode\" (expected NAME|DOCUMENT)."
            ),
        };
    }

    protected function renderNameMode(string $columnSql, string $termSql): string
    {
        $foldedColumn = "(ddd_fold($columnSql) COLLATE \"C\")";
        $strippedTerm = "regexp_replace(ddd_fold($termSql), '[+\\-*\"~]', '', 'g')";
        $terms = "unnest(regexp_split_to_array(btrim($strippedTerm), '\\s+'))";
        // a term is a literal prefix: LIKE's `\`, `%` and `_` in it are escaped (MariaDB's MATCH had no wildcards there)
        $likeTerm = "replace(replace(replace(ddd_term.t, '\\', '\\\\'), '%', '\\%'), '_', '\\_')";

        return "(NOT EXISTS (SELECT 1 FROM $terms AS ddd_term(t) WHERE ddd_term.t <> '' AND NOT "
            . "($foldedColumn LIKE ($likeTerm || '%') OR $foldedColumn LIKE ('% ' || $likeTerm || '%'))))";
    }
}
