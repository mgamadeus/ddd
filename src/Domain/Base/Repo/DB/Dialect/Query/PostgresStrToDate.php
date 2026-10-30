<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * PostgreSQL rendering of the `STR_TO_DATE` DQL name MariaDB registers via
 * `DoctrineExtensions\Query\Mysql\StrToDate` (architecture plan §4.8). Mig §3.6 replaces the two live constant-format
 * call sites (`TeamMembersService.php:125-126`) with a plain `TIMESTAMP '9999-12-31 00:00:00'` literal rather than
 * calling this function at all, so this class only needs to cover the generic case for any future/dynamic caller;
 * it translates MariaDB's handful of common `%`-format tokens to `to_timestamp()`'s tokens at SQL-render time
 * (both arguments stay plain text, so a runtime-computed format string is fine). Unmapped tokens pass through
 * unchanged, which `to_timestamp()` ignores as literal text — a caller using an exotic MariaDB token needs its own
 * translation; none does today. PostgreSQL only; registered by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}.
 *
 * DQL: `STR_TO_DATE(dateString, dateFormat)`
 * SQL: `to_timestamp(dateString, replace(replace(replace(replace(replace(replace(dateFormat,
 *       '%Y','YYYY'),'%m','MM'),'%d','DD'),'%H','HH24'),'%i','MI'),'%s','SS'))`
 */
class PostgresStrToDate extends FunctionNode
{
    /** @var array<string, string> MariaDB format token -> to_timestamp() format token */
    protected const array TOKEN_MAP = [
        '%Y' => 'YYYY',
        '%m' => 'MM',
        '%d' => 'DD',
        '%H' => 'HH24',
        '%i' => 'MI',
        '%s' => 'SS',
    ];

    protected mixed $dateString = null;

    protected mixed $dateFormat = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->dateString = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->dateFormat = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        $dateStringSql = $this->dateString->dispatch($sqlWalker);
        $formatSql = $this->dateFormat->dispatch($sqlWalker);

        foreach (self::TOKEN_MAP as $mariaDbToken => $postgresToken) {
            $formatSql = "replace($formatSql, '$mariaDbToken', '$postgresToken')";
        }

        return "to_timestamp($dateStringSql, $formatSql)";
    }
}
