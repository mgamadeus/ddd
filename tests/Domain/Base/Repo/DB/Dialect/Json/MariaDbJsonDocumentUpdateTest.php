<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect\Json;

use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonValueKind;
use DDD\Domain\Base\Repo\DB\Dialect\Json\MariaDbJsonDocumentUpdate;
use PHPUnit\Framework\TestCase;

/** Consecutive operations of one kind render as ONE MariaDB JSON function, so the document is parsed once per function. */
class MariaDbJsonDocumentUpdateTest extends TestCase
{
    public function testConsecutiveSetsRenderOneJsonSetWithSeveralPairs(): void
    {
        $documentSql = (new MariaDbJsonDocumentUpdate('`document`'))
            ->set(JsonPath::fromKeys('a'), ':a', JsonValueKind::NUMBER)
            ->set(JsonPath::fromKeys('b'), ':b', JsonValueKind::NUMBER)
            ->set(JsonPath::fromKeys('c'), ':c', JsonValueKind::NUMBER)
            ->toSql();

        $this->assertSame(1, substr_count($documentSql, 'JSON_SET('));
        $this->assertSame(
            "JSON_SET(COALESCE(`document`, '{}'), '$.\"a\"', (:a), '$.\"b\"', (:b), '$.\"c\"', (:c))",
            $documentSql
        );
    }

    public function testConsecutiveRemovesRenderOneJsonRemove(): void
    {
        $documentSql = (new MariaDbJsonDocumentUpdate('`document`'))
            ->remove(JsonPath::fromKeys('a'))
            ->remove(JsonPath::fromKeys('b'))
            ->toSql();

        $this->assertSame("JSON_REMOVE(COALESCE(`document`, '{}'), '$.\"a\"', '$.\"b\"')", $documentSql);
    }

    public function testAChangeOfOperationClosesTheFunctionAroundTheDocument(): void
    {
        $documentSql = (new MariaDbJsonDocumentUpdate('`document`'))
            ->setObjectUnlessPresent(JsonPath::fromKeys('scopes'))
            ->set(JsonPath::fromKeys('a'), ':a', JsonValueKind::NUMBER)
            ->remove(JsonPath::fromKeys('b'))
            ->mergePatch(':patch')
            ->set(JsonPath::fromKeys('c'), ':c', JsonValueKind::NUMBER)
            ->toSql();

        $this->assertSame(2, substr_count($documentSql, 'JSON_SET('));
        $this->assertSame(1, substr_count($documentSql, 'JSON_REMOVE('));
        $this->assertStringStartsWith('JSON_SET(JSON_MERGE_PATCH(JSON_REMOVE(JSON_SET(', $documentSql);
        $this->assertStringEndsWith(", :patch), '$.\"c\"', (:c))", $documentSql);
    }

    public function testToSqlDoesNotChangeTheBuilder(): void
    {
        $documentUpdate = (new MariaDbJsonDocumentUpdate('`document`'))->set(JsonPath::fromKeys('a'), ':a', JsonValueKind::NUMBER);
        $firstSql = $documentUpdate->toSql();

        $this->assertSame($firstSql, $documentUpdate->toSql());
        $this->assertSame(
            "JSON_SET(COALESCE(`document`, '{}'), '$.\"a\"', (:a), '$.\"b\"', (:b))",
            $documentUpdate->set(JsonPath::fromKeys('b'), ':b', JsonValueKind::NUMBER)->toSql()
        );
    }
}
