<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Issues;

use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssueGroupsFilter;
use DDD\Domain\Common\Services\IssueGroupsService;
use DDD\Domain\Common\Services\IssuesRegistryJobService;
use DDD\Domain\Common\Services\RuntimeErrorsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Infrastructure\Logging\Query\Elasticsearch\ElasticsearchIssuesQueryBackend;
use DDD\Infrastructure\Logging\Query\Elasticsearch\RuntimeErrorAggregationQuery;
use DDD\Infrastructure\Logging\Query\IssueEventSearch;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Common\RuntimeErrors\RuntimeErrorsExtract;
use DDD\Symfony\Commands\Common\RuntimeErrors\RuntimeErrorsProbe;
use DDD\Tests\Infrastructure\Logging\IssuesFixtureThrower;
use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Elasticsearch / OpenSearch backend against a real cluster (DDD_TEST_ES_URL: the `elasticsearch` or `opensearch`
 * service of tests/Integration/docker-compose.yml): real issues lines indexed with dynamic mapping (string fields get
 * a `.keyword` sub-field, as with Logstash) and with keyword mapping (suffix ''), then the registry job, the event
 * search, the runtime-error groups / detail / trend and `app:runtime-errors:extract|probe`.
 */
class ElasticsearchIssuesBackendTest extends IssuesRegistryTestCase
{
    protected string $elasticsearchUrl = '';

    protected string $indexName = '';

    protected function setUp(): void
    {
        $this->elasticsearchUrl = rtrim((string)(getenv('DDD_TEST_ES_URL') ?: ($_SERVER['DDD_TEST_ES_URL'] ?? '')), '/');
        if ($this->elasticsearchUrl === '') {
            $this->markTestSkipped('Elasticsearch: start the elasticsearch or opensearch service of tests/Integration/docker-compose.yml and set DDD_TEST_ES_URL');
        }
        parent::setUp();
        $this->indexName = 'ddd-it-issues-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->indexName !== '' && $this->elasticsearchUrl !== '') {
            (new Client())->delete($this->elasticsearchUrl . '/' . $this->indexName, ['http_errors' => false]);
        }
        DDDService::instance()->getService(RuntimeErrorsService::class)->setElasticsearchIssuesQueryBackend(null);
        parent::tearDown();
    }

    protected function createSettings(string $keywordSuffix = '.keyword'): IssuesRegistrySettings
    {
        return new IssuesRegistrySettings(
            queryBackend: IssuesRegistrySettings::BACKEND_ELASTICSEARCH,
            queryUrl: $this->elasticsearchUrl,
            querySelector: $this->indexName,
            seedHours: 0,
            elasticsearchKeywordSuffix: $keywordSuffix,
        );
    }

    /**
     * Indexes three real lines (two RuntimeException through two routes, one PDOException) plus a warning; with
     * `$keywordMapping` the index maps every string as keyword (an ECS index template), else dynamically.
     */
    protected function indexLines(bool $keywordMapping = false): void
    {
        $httpClient = new Client();
        $indexDefinition = $keywordMapping
            ? ['mappings' => ['dynamic_templates' => [['strings' => ['match_mapping_type' => 'string', 'mapping' => ['type' => 'keyword']]]]]]
            : new \stdClass();
        $httpClient->put($this->elasticsearchUrl . '/' . $this->indexName, ['json' => $indexDefinition]);
        $issuesFixtureThrower = new IssuesFixtureThrower();
        $router = $this->createMock(RouterInterface::class);
        $context = ['lastLocationId' => 77, 'workspace' => 'mgn', 'environment' => 'test', 'account.id' => 4183511];
        $jsonLines = [
            ...IssuesLogLineWriter::writeLines($router, $context + ['route_name' => 'api_orders_list'], $issuesFixtureThrower->runtimeError(1, 'failed for id 42')),
            ...IssuesLogLineWriter::writeLines($router, $context + ['route_name' => 'api_orders_show'], $issuesFixtureThrower->runtimeError(1, 'failed for id 43 token=abc')),
            ...IssuesLogLineWriter::writeLines($router, ['lastLocationId' => 78, 'route_name' => 'api_orders_list'], $issuesFixtureThrower->databaseError('42S02')),
        ];
        $warningLines = IssuesLogLineWriter::writeLines($router, $context, $issuesFixtureThrower->engineError('just a warning'));
        $jsonLines[] = str_replace('"log.level":"ERROR"', '"log.level":"WARNING"', $warningLines[0]);
        $bulkBody = '';
        foreach ($jsonLines as $jsonLine) {
            $bulkBody .= json_encode(['index' => ['_index' => $this->indexName]]) . "\n" . $jsonLine . "\n";
        }
        $response = $httpClient->post($this->elasticsearchUrl . '/_bulk?refresh=true', [
            'headers' => ['Content-Type' => 'application/x-ndjson'],
            'body' => $bulkBody,
        ]);
        self::assertFalse(json_decode((string)$response->getBody(), true)['errors'], (string)$response->getBody());
    }

    public function testRegistryJobOverElasticsearch(): void
    {
        $this->indexLines();
        $issuesRegistrySettings = $this->createSettings();
        $issuesRegistryJobService = new IssuesRegistryJobService();
        $issuesRegistryJobService->setSettings($issuesRegistrySettings);
        $recordingIssuesAlertWebhookSender = new RecordingIssuesAlertWebhookSender(new IssuesRegistrySettings(alertWebhook: 'http://webhook.invalid'));
        $issuesRegistryJobService->setAlertWebhookSender($recordingIssuesAlertWebhookSender);

        $firstRunResult = $issuesRegistryJobService->run(DateTime::fromTimestamp(time() + 120));
        $secondRunResult = $issuesRegistryJobService->run(DateTime::fromTimestamp(time() + 180));

        self::assertSame(2, $firstRunResult->groupsCreated);
        self::assertSame(2, $firstRunResult->issuesCreated);   // one entry point (no request): one issue per group
        self::assertSame(3, $firstRunResult->eventsCounted);
        self::assertSame(0, $secondRunResult->groupsCreated);
        self::assertCount(2, $recordingIssuesAlertWebhookSender->sentTexts);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var IssueGroupsService $issueGroupsService */
            $issueGroupsService = IssueGroup::getService();
            $issueGroups = $issueGroupsService->listGroups(new IssueGroupsFilter());
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $eventCounts = [];
        foreach ($issueGroups->getElements() as $issueGroup) {
            $eventCounts[$issueGroup->exceptionType] = $issueGroup->eventCount;
            self::assertSame('r-test', $issueGroup->releaseFirstSeen);
        }
        ksort($eventCounts);
        self::assertSame(['PDOException' => 1, 'RuntimeException' => 2], $eventCounts);
    }

    public function testKeywordMappedIndexWithoutSuffix(): void
    {
        $this->indexLines(keywordMapping: true);
        $elasticsearchIssuesQueryBackend = new ElasticsearchIssuesQueryBackend($this->createSettings(keywordSuffix: ''));

        $issueHashCounts = $elasticsearchIssuesQueryBackend->aggregateErrors(
            DateTime::fromTimestamp(time() - 600),
            DateTime::fromTimestamp(time() + 600)
        );

        self::assertCount(2, $issueHashCounts->getGroupHashes());
        self::assertSame(3, array_sum(array_map(static fn($issueHashCount): int => $issueHashCount->count, $issueHashCounts->getElements())));
    }

    public function testEventSearch(): void
    {
        $this->indexLines();
        $elasticsearchIssuesQueryBackend = new ElasticsearchIssuesQueryBackend($this->createSettings());
        $from = DateTime::fromTimestamp(time() - 600);
        $to = DateTime::fromTimestamp(time() + 600);

        $textEvents = $elasticsearchIssuesQueryBackend->searchEvents(new IssueEventSearch($from, $to, text: 'id 42'));
        $routeEvents = $elasticsearchIssuesQueryBackend->searchEvents(new IssueEventSearch($from, $to, routeName: 'api_orders_list'));
        $warningEvents = $elasticsearchIssuesQueryBackend->searchEvents(new IssueEventSearch($from, $to, levels: ['warning']));
        $accountEvents = $elasticsearchIssuesQueryBackend->searchEvents(new IssueEventSearch($from, $to, accountId: '4183511'));

        $groupHash = $routeEvents->first()->groupHash;
        $groupEvents = $elasticsearchIssuesQueryBackend->searchEvents(new IssueEventSearch($from, $to, groupHash: $groupHash, oldestFirst: true));

        self::assertSame(1, $textEvents->count());
        self::assertStringEndsWith('failed for id 42', (string)$textEvents->first()->message);
        self::assertSame(2, $routeEvents->count());
        self::assertSame(1, $warningEvents->count());
        self::assertSame(2, $accountEvents->count());
        self::assertGreaterThanOrEqual(1, $groupEvents->count());
        self::assertTrue($elasticsearchIssuesQueryBackend->probe()->reachable);
    }

    public function testWorkspaceFilter(): void
    {
        $httpClient = new Client();
        $httpClient->put($this->elasticsearchUrl . '/' . $this->indexName, ['json' => new \stdClass()]);
        $router = $this->createMock(RouterInterface::class);
        $issuesFixtureThrower = new IssuesFixtureThrower();
        $bulkBody = '';
        foreach ([
            ...IssuesLogLineWriter::writeLinesForWorkspace('mgn', $router, [], $issuesFixtureThrower->runtimeError(1)),
            ...IssuesLogLineWriter::writeLinesForWorkspace('ame', $router, [], $issuesFixtureThrower->runtimeError(1)),
        ] as $jsonLine) {
            $bulkBody .= json_encode(['index' => ['_index' => $this->indexName]]) . "\n" . $jsonLine . "\n";
        }
        $httpClient->post($this->elasticsearchUrl . '/_bulk?refresh=true', ['headers' => ['Content-Type' => 'application/x-ndjson'], 'body' => $bulkBody]);
        $elasticsearchIssuesQueryBackend = new ElasticsearchIssuesQueryBackend($this->createSettings());

        $workspaceEvents = $elasticsearchIssuesQueryBackend->searchEvents(new IssueEventSearch(
            DateTime::fromTimestamp(time() - 600),
            DateTime::fromTimestamp(time() + 600),
            workspace: 'mgn',
        ));

        self::assertSame(1, $workspaceEvents->count());
        self::assertSame('mgn', $workspaceEvents->first()->workspace);
    }

    public function testRuntimeErrorGroupsDetailAndTrend(): void
    {
        $this->indexLines();
        /** @var RuntimeErrorsService $runtimeErrorsService */
        $runtimeErrorsService = DDDService::instance()->getService(RuntimeErrorsService::class);
        $runtimeErrorsService->setElasticsearchIssuesQueryBackend(new ElasticsearchIssuesQueryBackend($this->createSettings()));

        $coarseGroups = $runtimeErrorsService->findErrorGroups();
        $fingerprintGroups = $runtimeErrorsService->findErrorGroups(groupingDimension: RuntimeErrorAggregationQuery::DIMENSION_FINGERPRINT);
        $accountGroups = $runtimeErrorsService->findErrorGroups(accountId: 4183511, workspace: 'mgn');
        $searchGroups = $runtimeErrorsService->findErrorGroups(searchTerm: 'PDOExc');
        $trend = $runtimeErrorsService->findErrorTrend(hours: 6);

        self::assertSame(2, $coarseGroups->count());
        $topGroup = $coarseGroups->first();
        self::assertSame('RuntimeException', $topGroup->errorType);
        self::assertSame(2, $topGroup->count);
        self::assertSame(1, $topGroup->affectedLocationCount);
        self::assertSame('RuntimeException|IssuesFixtureThrower.php', $topGroup->displayFingerprint);
        self::assertNull($topGroup->canonicalFingerprint);
        self::assertSame('mgn', $topGroup->workspace);
        self::assertGreaterThanOrEqual(2, $fingerprintGroups->count());
        self::assertNotNull($fingerprintGroups->first()->canonicalFingerprint);
        self::assertSame(1, $accountGroups->count());
        self::assertSame(1, $searchGroups->count());
        self::assertSame(3, array_sum(array_map(static fn($trendBucket): int => $trendBucket->count, $trend->getElements())));

        $runtimeErrorGroupDetail = $runtimeErrorsService->findErrorGroupDetail($topGroup->displayFingerprint, sampleSize: 5);
        self::assertSame(2, $runtimeErrorGroupDetail->group->count);
        self::assertSame(2, $runtimeErrorGroupDetail->recentOccurrences->count());
        self::assertSame(2, $runtimeErrorGroupDetail->routeBreakdown->count());
        self::assertNotNull($runtimeErrorGroupDetail->lastSeenTimestamp);
        // both lines share a millisecond: which one is "latest" is up to the cluster, the redaction is not
        $occurrenceMessages = implode("\n", array_map(static fn($occurrence): string => (string)$occurrence->message, $runtimeErrorGroupDetail->recentOccurrences->getElements()));
        self::assertStringContainsString('token=[REDACTED]', $occurrenceMessages);
        self::assertStringNotContainsString('token=abc', $occurrenceMessages);
        self::assertSame(2, array_sum(array_map(static fn($trendBucket): int => $trendBucket->count, $runtimeErrorGroupDetail->perGroupTrend->getElements())));

        $absentDetail = $runtimeErrorsService->findErrorGroupDetail('NoSuchException|Nowhere.php');
        self::assertSame(0, $absentDetail->group->count);
        self::assertSame(0, $absentDetail->recentOccurrences->count());
    }

    public function testExtractAndProbeCommands(): void
    {
        $this->indexLines();
        /** @var RuntimeErrorsService $runtimeErrorsService */
        $runtimeErrorsService = DDDService::instance()->getService(RuntimeErrorsService::class);
        $runtimeErrorsService->setElasticsearchIssuesQueryBackend(new ElasticsearchIssuesQueryBackend($this->createSettings()));

        $extractCommandTester = new CommandTester(new RuntimeErrorsExtract());
        self::assertSame(Command::SUCCESS, $extractCommandTester->execute(['--minutes' => '30', '--limit' => '5']));
        $payload = json_decode($extractCommandTester->getDisplay(), true);
        self::assertSame(['environment', 'workspace', 'accountId', 'locationId', 'window', 'minutes', 'hours', 'levels', 'limit'], array_keys($payload['filters']));
        self::assertSame('30m', $payload['filters']['window']);
        self::assertSame(2, $payload['count']);
        self::assertSame(
            ['errorType', 'message', 'throwSite', 'route', 'count', 'affectedLocationCount', 'environment', 'errorCode', 'errorKind', 'firstSeen', 'lastSeen', 'fingerprint', 'stackTrace'],
            array_keys($payload['errors'][0])
        );
        self::assertMatchesRegularExpression('/^IssuesFixtureThrower\.php:\d+$/', $payload['errors'][0]['throwSite']);

        self::assertSame(Command::INVALID, (new CommandTester(new RuntimeErrorsExtract()))->execute(['--logstashEnv' => 'STAGING']));

        $probeCommandTester = new CommandTester(new RuntimeErrorsProbe());
        self::assertSame(Command::SUCCESS, $probeCommandTester->execute([]));
        self::assertStringContainsString('Total ERROR/CRITICAL hits: 3', $probeCommandTester->getDisplay());
        self::assertStringContainsString('OK   multi_terms [error.type.keyword, log.origin.file.name.keyword]', $probeCommandTester->getDisplay());
    }
}
