<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect\Query;

use DDD\Tests\Integration\Query\Support\MainSqlRenderingRowNumberPagePagination;
use PHPUnit\Framework\TestCase;

/** The page rank goes into the main query's own ORDER BY only, and under MIN() when the query is grouped */
class RowNumberPagePaginationOrderByTest extends TestCase
{
    protected const string ID_SQL = 'SELECT id_0, MIN(sclr_1) AS dctrn_minrownum FROM (SELECT p.id AS id_0, ROW_NUMBER() OVER (ORDER BY p.key ASC) AS sclr_1 FROM parents p) dctrn_result GROUP BY id_0';

    protected function inject(string $mainSql, bool $isGrouped = false): string
    {
        return (new MainSqlRenderingRowNumberPagePagination())->injectIdPageJoinInto($mainSql, self::ID_SQL, 'id_0', 'p0_', $isGrouped);
    }

    public function testTheRankLeadsTheQuerysOwnOrderBy(): void
    {
        $injectedSql = $this->inject('SELECT p0_.id FROM "parents" p0_ WHERE p0_.id IN (SELECT c1_.parent_id FROM children c1_ ORDER BY c1_.label) ORDER BY p0_.key ASC');

        self::assertStringContainsString('(SELECT c1_.parent_id FROM children c1_ ORDER BY c1_.label)', $injectedSql);
        self::assertStringEndsWith('ORDER BY page.dctrn_minrownum, p0_.key ASC', $injectedSql);
    }

    public function testWithoutAnOrderByOfItsOwnTheQueryGetsOne(): void
    {
        $injectedSql = $this->inject('SELECT p0_.id, string_agg(c1_.label, \',\' ORDER BY c1_.label) FROM "parents" p0_');

        self::assertStringContainsString("string_agg(c1_.label, ',' ORDER BY c1_.label)", $injectedSql);
        self::assertStringEndsWith(' ORDER BY page.dctrn_minrownum', $injectedSql);
    }

    public function testQuotedTextNeverCountsAsAnOrderBy(): void
    {
        $scanner = new MainSqlRenderingRowNumberPagePagination();

        self::assertNull($scanner->findTopLevelOrderByListOffsetOf('SELECT \'it\'\'s ( ORDER BY x\' AS "a ORDER BY b" FROM t'));
        self::assertNull($scanner->findTopLevelOrderByListOffsetOf('SELECT 1 FROM t WHERE reorder_by = 1'));
    }

    public function testAGroupedQueryOrdersByTheMinimumRank(): void
    {
        $injectedSql = $this->inject('SELECT p0_.id FROM "parents" p0_ GROUP BY p0_.id ORDER BY COUNT(c1_.id) DESC', isGrouped: true);

        self::assertStringEndsWith('ORDER BY MIN(page.dctrn_minrownum), COUNT(c1_.id) DESC', $injectedSql);
    }

    /** PostgreSQL: the ORDER BY of a SELECT DISTINCT may only use expressions of its select list (SQLSTATE 42P10) */
    public function testADistinctQuerySelectsTheRankItOrdersBy(): void
    {
        $injectedSql = $this->inject('SELECT DISTINCT p0_.id AS id_0, p0_.key AS key_1, c1_.id AS id_2 FROM "parents" p0_ LEFT JOIN children c1_ ON c1_.parent_id = p0_.id ORDER BY p0_.key ASC');

        self::assertStringStartsWith('SELECT DISTINCT page.dctrn_minrownum AS dctrn_page_rank, p0_.id AS id_0, p0_.key AS key_1, c1_.id AS id_2 FROM "parents" p0_ INNER JOIN', $injectedSql);
        self::assertStringEndsWith('ORDER BY page.dctrn_minrownum, p0_.key ASC', $injectedSql);
    }

    public function testAGroupedDistinctQuerySelectsTheMinimumRank(): void
    {
        $injectedSql = $this->inject('SELECT DISTINCT p0_.id AS id_0 FROM "parents" p0_ GROUP BY p0_.id ORDER BY p0_.id ASC', isGrouped: true);

        self::assertStringStartsWith('SELECT DISTINCT MIN(page.dctrn_minrownum) AS dctrn_page_rank, p0_.id AS id_0 FROM', $injectedSql);
        self::assertStringEndsWith('ORDER BY MIN(page.dctrn_minrownum), p0_.id ASC', $injectedSql);
    }

    public function testAQueryWithoutDistinctKeepsItsSelectList(): void
    {
        $injectedSql = $this->inject('SELECT p0_.id AS id_0 FROM "parents" p0_ ORDER BY p0_.key ASC');

        self::assertStringStartsWith('SELECT p0_.id AS id_0 FROM "parents" p0_ INNER JOIN', $injectedSql);
    }
}
