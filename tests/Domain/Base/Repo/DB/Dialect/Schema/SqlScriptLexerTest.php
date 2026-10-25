<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect\Schema;

use DDD\Domain\Base\Repo\DB\Dialect\Schema\SqlScriptLexer;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see SqlScriptLexer}: the generator gap this closes is a naive `explode(';', …)` splitting a
 * MariaDB `BEGIN…END` trigger body or a PostgreSQL `$tag$`-quoted function body on every semicolon inside it
 * (Arch §4.10).
 */
final class SqlScriptLexerTest extends TestCase
{
    public function testSimpleStatementsSplitOnSemicolon(): void
    {
        $statements = SqlScriptLexer::splitStatements('CREATE TABLE a (id int); CREATE TABLE b (id int);');

        $this->assertSame(
            ['CREATE TABLE a (id int)', 'CREATE TABLE b (id int)'],
            $statements
        );
    }

    public function testTrailingAndDoubleSemicolonsProduceNoEmptyStatements(): void
    {
        $statements = SqlScriptLexer::splitStatements('SELECT 1;; SELECT 2;   ');

        $this->assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    public function testSemicolonInsideSingleQuotedStringIsNotAStatementBoundary(): void
    {
        $statements = SqlScriptLexer::splitStatements("INSERT INTO t (msg) VALUES ('a; b'); SELECT 1;");

        $this->assertSame(
            ["INSERT INTO t (msg) VALUES ('a; b')", 'SELECT 1'],
            $statements
        );
    }

    public function testEscapedAndDoubledQuotesDoNotCloseTheString(): void
    {
        $statements = SqlScriptLexer::splitStatements(
            "SELECT 'it''s; here', 'back\\'slash; here';\nSELECT 2;"
        );

        $this->assertSame(
            ["SELECT 'it''s; here', 'back\\'slash; here'", 'SELECT 2'],
            $statements
        );
    }

    public function testBacktickAndDoubleQuotedIdentifiersProtectSemicolons(): void
    {
        $statements = SqlScriptLexer::splitStatements(
            'SELECT `weird;name` FROM t; SELECT "also;weird" FROM u;'
        );

        $this->assertSame(
            ['SELECT `weird;name` FROM t', 'SELECT "also;weird" FROM u'],
            $statements
        );
    }

    public function testLineCommentsDoNotHideAStatementBoundary(): void
    {
        $statements = SqlScriptLexer::splitStatements(
            "SELECT 1; -- a comment with a ; inside\nSELECT 2; # another ; comment\nSELECT 3;"
        );

        $this->assertCount(3, $statements);
        $this->assertStringStartsWith('SELECT 1', $statements[0]);
        $this->assertStringContainsString('SELECT 2', $statements[1]);
        $this->assertStringContainsString('SELECT 3', $statements[2]);
    }

    public function testDoubleMinusFollowedByNonWhitespaceIsNotAToComment(): void
    {
        // "a--b" (no space after --) is not a line comment under the SQL-standard rule this lexer applies —
        // it is still scanned character by character and the semicolon after it remains a real boundary.
        $statements = SqlScriptLexer::splitStatements('SELECT a--b; SELECT 2;');

        $this->assertSame(['SELECT a--b', 'SELECT 2'], $statements);
    }

    public function testBlockCommentDoesNotHideASemicolon(): void
    {
        $statements = SqlScriptLexer::splitStatements(
            'SELECT 1 /* a ; comment ; block */; SELECT 2;'
        );

        $this->assertSame(['SELECT 1 /* a ; comment ; block */', 'SELECT 2'], $statements);
    }

    public function testMariaDbBeginEndTriggerBodyIsOneStatement(): void
    {
        $sql = <<<'SQL'
            DROP TRIGGER IF EXISTS onThing;
            CREATE TRIGGER onThing
                BEFORE INSERT
                ON Things
                FOR EACH ROW
            BEGIN
                DECLARE msg VARCHAR(512);
                IF NEW.id IS NOT NULL THEN
                    SET msg = 'bad; value';
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
                END IF;
            END
            SQL;

        $statements = SqlScriptLexer::splitStatements($sql);

        $this->assertCount(2, $statements);
        $this->assertSame('DROP TRIGGER IF EXISTS onThing', $statements[0]);
        $this->assertStringStartsWith('CREATE TRIGGER onThing', $statements[1]);
        $this->assertStringContainsString("SET msg = 'bad; value';", $statements[1]);
        $this->assertStringEndsWith('END', $statements[1]);
    }

    public function testDollarQuotedFunctionBodyWithNamedTagIsOneStatement(): void
    {
        $sql = <<<'SQL'
            CREATE OR REPLACE FUNCTION f() RETURNS trigger
                LANGUAGE plpgsql
                AS $ddd$
                BEGIN
                    IF NEW.id IS NOT NULL THEN
                        RAISE EXCEPTION 'bad; value; here';
                    END IF;
                    RETURN NEW;
                END;
                $ddd$;
            DROP TRIGGER IF EXISTS t ON "Things";
            CREATE TRIGGER t BEFORE INSERT ON "Things" FOR EACH ROW EXECUTE FUNCTION f();
            SQL;

        $statements = SqlScriptLexer::splitStatements($sql);

        $this->assertCount(3, $statements);
        $this->assertStringStartsWith('CREATE OR REPLACE FUNCTION f()', $statements[0]);
        $this->assertStringContainsString("RAISE EXCEPTION 'bad; value; here';", $statements[0]);
        $this->assertStringContainsString('$ddd$', $statements[0]);
        $this->assertSame('DROP TRIGGER IF EXISTS t ON "Things"', $statements[1]);
        $this->assertStringStartsWith('CREATE TRIGGER t', $statements[2]);
    }

    public function testDollarQuotedBodyWithEmptyTagIsOneStatement(): void
    {
        $sql = 'CREATE FUNCTION f() RETURNS int LANGUAGE sql AS $$ SELECT 1; SELECT 2; $$; SELECT 3;';

        $statements = SqlScriptLexer::splitStatements($sql);

        $this->assertCount(2, $statements);
        $this->assertSame('CREATE FUNCTION f() RETURNS int LANGUAGE sql AS $$ SELECT 1; SELECT 2; $$', $statements[0]);
        $this->assertSame('SELECT 3', $statements[1]);
    }

    public function testDelimiterCommandChangesTheActiveTerminatorAndIsNotItselfAStatement(): void
    {
        $sql = <<<'SQL'
            DROP TRIGGER IF EXISTS onThing;
            DELIMITER $$
            CREATE TRIGGER onThing BEFORE INSERT ON Things FOR EACH ROW
            BEGIN
                SET @x = 1; SET @y = 2;
            END$$
            DELIMITER ;
            SELECT 1;
            SQL;

        $statements = SqlScriptLexer::splitStatements($sql);

        $this->assertCount(3, $statements);
        $this->assertSame('DROP TRIGGER IF EXISTS onThing', $statements[0]);
        $this->assertStringContainsString('SET @x = 1; SET @y = 2;', $statements[1]);
        $this->assertStringEndsWith('END', $statements[1]);
        $this->assertSame('SELECT 1', $statements[2]);
    }

    public function testDelimiterTokenItselfIsNotMisreadAsADollarQuoteOpener(): void
    {
        // While the active delimiter IS "$$" (set via DELIMITER), a bare "$$" in the source must end the
        // statement, not open a PostgreSQL-style dollar-quoted region (the two conventions never coexist in one
        // real script, but the lexer must still resolve the ambiguity deterministically).
        $sql = "DELIMITER $$\nSELECT 1$$\nDELIMITER ;\nSELECT 2;";

        $statements = SqlScriptLexer::splitStatements($sql);

        $this->assertSame(['SELECT 1', 'SELECT 2'], $statements);
    }

    public function testEmptyScriptProducesNoStatements(): void
    {
        $this->assertSame([], SqlScriptLexer::splitStatements(''));
        $this->assertSame([], SqlScriptLexer::splitStatements("   \n -- only a comment\n"));
    }
}
