<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect\Runtime;

use DDD\Domain\Base\Repo\DB\Dialect\Runtime\BacktickQuoteSqlRewriter;
use PHPUnit\Framework\TestCase;

/** D1/§4.5: backticks -> double quotes outside string literals, comments and $tag$ bodies. */
final class BacktickQuoteSqlRewriterTest extends TestCase
{
    public function testNoBacktickIsReturnedUnchangedByTheFastPath(): void
    {
        $sql = 'SELECT 1 FROM "Accounts" WHERE id = ?';
        $this->assertSame($sql, BacktickQuoteSqlRewriter::rewrite($sql));
    }

    public function testBacktickedIdentifiersAndAliasesAreRewritten(): void
    {
        $this->assertSame(
            'SELECT COUNT(*) AS "entryCount" FROM "TrackGeoHashes"',
            BacktickQuoteSqlRewriter::rewrite('SELECT COUNT(*) AS `entryCount` FROM `TrackGeoHashes`')
        );
    }

    public function testABacktickInsideASingleQuotedLiteralIsLeftAlone(): void
    {
        $sql = "SELECT * FROM `Tracks` WHERE label = 'can`t stop'";
        $this->assertSame(
            'SELECT * FROM "Tracks" WHERE label = \'can`t stop\'',
            BacktickQuoteSqlRewriter::rewrite($sql)
        );
    }

    public function testADoubledSingleQuoteEscapeInsideALiteralIsHandled(): void
    {
        $sql = "SELECT * FROM `Tracks` WHERE label = 'it''s `fine`'";
        $this->assertSame(
            'SELECT * FROM "Tracks" WHERE label = \'it\'\'s `fine`\'',
            BacktickQuoteSqlRewriter::rewrite($sql)
        );
    }

    public function testABacktickInsideALineCommentIsLeftAlone(): void
    {
        $sql = "SELECT * FROM `Tracks` -- a `comment` with backticks\nWHERE id = 1";
        $this->assertSame(
            "SELECT * FROM \"Tracks\" -- a `comment` with backticks\nWHERE id = 1",
            BacktickQuoteSqlRewriter::rewrite($sql)
        );
    }

    public function testABacktickInsideABlockCommentIsLeftAlone(): void
    {
        $sql = 'SELECT * FROM `Tracks` /* a `comment` */ WHERE id = 1';
        $this->assertSame(
            'SELECT * FROM "Tracks" /* a `comment` */ WHERE id = 1',
            BacktickQuoteSqlRewriter::rewrite($sql)
        );
    }

    public function testANestedBlockCommentIsHandled(): void
    {
        $sql = 'SELECT * FROM `Tracks` /* outer /* inner `x` */ still comment */ WHERE id = 1';
        $this->assertSame(
            'SELECT * FROM "Tracks" /* outer /* inner `x` */ still comment */ WHERE id = 1',
            BacktickQuoteSqlRewriter::rewrite($sql)
        );
    }

    public function testABacktickInsideADollarTaggedBodyIsLeftAlone(): void
    {
        $sql = 'CREATE FUNCTION f() RETURNS text AS $tag$ SELECT \'`not an identifier`\' $tag$ LANGUAGE sql; SELECT * FROM `Tracks`';
        $this->assertSame(
            'CREATE FUNCTION f() RETURNS text AS $tag$ SELECT \'`not an identifier`\' $tag$ LANGUAGE sql; SELECT * FROM "Tracks"',
            BacktickQuoteSqlRewriter::rewrite($sql)
        );
    }

    public function testABacktickInsideAnUntaggedDollarBodyIsLeftAlone(): void
    {
        $sql = 'AS $$ SELECT \'`x`\' $$ ; SELECT * FROM `Tracks`';
        $this->assertSame(
            'AS $$ SELECT \'`x`\' $$ ; SELECT * FROM "Tracks"',
            BacktickQuoteSqlRewriter::rewrite($sql)
        );
    }

    public function testABareDollarSignNotOpeningATagIsLeftAlone(): void
    {
        // A bind placeholder like $1 is not a dollar-quote opening (no matching closing $1 expected).
        $sql = 'SELECT * FROM `Tracks` WHERE id = $1';
        $this->assertSame('SELECT * FROM "Tracks" WHERE id = $1', BacktickQuoteSqlRewriter::rewrite($sql));
    }

    public function testAnAlreadyDoubleQuotedIdentifierIsLeftAlone(): void
    {
        $sql = 'SELECT "weird`name" FROM `Tracks`';
        $this->assertSame('SELECT "weird`name" FROM "Tracks"', BacktickQuoteSqlRewriter::rewrite($sql));
    }
}
