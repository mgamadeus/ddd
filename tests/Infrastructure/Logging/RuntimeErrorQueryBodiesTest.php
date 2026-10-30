<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use DDD\Infrastructure\Logging\Query\Elasticsearch\ElasticsearchFieldNames;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorAggregationQuery as Query;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorDetailAggregationQuery as DetailQuery;
use PHPUnit\Framework\TestCase;

/**
 * The Elasticsearch bodies equal rankingCoach's runtime-error dashboard bodies byte for byte (key order included).
 * Fixtures/rc-runtime-errors-bodies.json was produced by RC's own query classes with the same arguments. The detail's
 * `recent_occurrences` additionally reads {@see DetailQuery::OCCURRENCE_SOURCE_FIELDS}; everything else is unchanged.
 */
class RuntimeErrorQueryBodiesTest extends TestCase
{
    /** @return array<string, array{0: string, 1: callable}> */
    public static function bodyCases(): array
    {
        return [
            'groups_default' => ['groups_default', static fn(): array => Query::build()->toGroupsBody()],
            'groups_fingerprint_filters' => ['groups_fingerprint_filters', static fn(): array => Query::build(
                72, ['ERROR'], 20, null, Query::DIMENSION_FINGERPRINT, 4183511, 77, 'App\\Foo*?', 'mgn'
            )->toGroupsBody()],
            'groups_minutes' => ['groups_minutes', static fn(): array => Query::build(24, [], 5, null, Query::DIMENSION_COARSE, minutes: 5)->toGroupsBody()],
            'trend_default' => ['trend_default', static fn(): array => Query::build()->toTrendBody()],
            'trend_long' => ['trend_long', static fn(): array => Query::build(720, ['ERROR', 'CRITICAL'], 50, null, workspace: 'ivs')->toTrendBody()],
            'detail_coarse' => ['detail_coarse', static fn(): array => DetailQuery::build(
                'PDOException|src/Domain/Foo.php', 24, ['ERROR', 'CRITICAL'], null, 5, Query::DIMENSION_COARSE, 'mgn'
            )->toDetailBody()],
            'detail_coarse_missing' => ['detail_coarse_missing', static fn(): array => DetailQuery::build('—|—', 6)->toDetailBody()],
            'detail_fingerprint' => ['detail_fingerprint', static fn(): array => DetailQuery::build(
                'abc123', 48, ['CRITICAL'], '1h', 80, Query::DIMENSION_FINGERPRINT
            )->toDetailBody()],
            'detail_bad_arity' => ['detail_bad_arity', static fn(): array => DetailQuery::build('only-one-part')->toDetailBody()],
        ];
    }

    /**
     * @dataProvider bodyCases
     */
    public function testBodyEqualsRankingCoachBody(string $fixtureKey, callable $buildBody): void
    {
        $fixtures = json_decode((string)file_get_contents(__DIR__ . '/Fixtures/rc-runtime-errors-bodies.json'), true);
        $expectedBody = $fixtures[$fixtureKey];
        if (str_starts_with($fixtureKey, 'detail_')) {
            $expectedBody['aggs'][Query::OUTER_AGG_KEY]['aggs']['recent_occurrences']['top_hits']['_source'] = [
                ...$expectedBody['aggs'][Query::OUTER_AGG_KEY]['aggs']['recent_occurrences']['top_hits']['_source'],
                ...DetailQuery::OCCURRENCE_SOURCE_FIELDS,
            ];
        }

        self::assertSame(
            json_encode($expectedBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($buildBody(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    public function testOnlyTheDetailOccurrencesReadTheOccurrenceFields(): void
    {
        $groupsBody = json_encode(Query::build()->toGroupsBody());
        $detailBody = DetailQuery::build('abc123', groupingDimension: Query::DIMENSION_FINGERPRINT)->toDetailBody();

        self::assertStringNotContainsString('error.frames', $groupsBody);
        self::assertStringNotContainsString('messenger.*', $groupsBody);
        self::assertNotContains('error.frames', $detailBody['aggs'][Query::OUTER_AGG_KEY]['aggs']['latest']['top_hits']['_source']);
        self::assertContains('error.frames', $detailBody['aggs'][Query::OUTER_AGG_KEY]['aggs']['recent_occurrences']['top_hits']['_source']);
    }

    public function testFieldsMappedAsKeywordDropTheSuffix(): void
    {
        $fieldNames = new ElasticsearchFieldNames(keywordSuffix: '', locationField: 'location.id', workspaceField: 'service.node.name');

        $body = json_encode(Query::build(workspace: 'w1', locationId: 5, fieldNames: $fieldNames)->toGroupsBody());

        self::assertStringNotContainsString('.keyword', $body);
        self::assertStringContainsString('"log.level":["ERROR","CRITICAL"]', $body);
        self::assertStringContainsString('{"term":{"location.id":5}}', $body);
        self::assertStringContainsString('{"term":{"service.node.name":"w1"}}', $body);
        self::assertStringContainsString('"cardinality":{"field":"location.id"}', $body);
    }
}
