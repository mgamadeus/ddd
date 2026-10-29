<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Issues;

use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueEvent;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrame;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueReport;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssueGroupsFilter;
use DDD\Domain\Common\Services\IssueGroupsService;
use DDD\Domain\Common\Services\IssuesRegistryJobService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\IssuesAlertWebhookSender;
use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Infrastructure\Logging\Query\IssueEventSearch;
use DDD\Infrastructure\Logging\Query\LokiIssuesQueryBackend;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Tests\Infrastructure\Logging\IssuesFixtureThrower;
use Elastic\Monolog\Formatter\ElasticCommonSchemaFormatter;
use GuzzleHttp\Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

/**
 * End to end: real issues lines (IssuesLogService + the ECS formatter) pushed into the harness Loki, the job with the
 * Loki backend aggregating and searching them, the alert posted to a real HTTP webhook stub (php -S). Runs only with
 * DDD_TEST_LOKI_URL (e.g. http://127.0.0.1:3110 — the `loki` service of tests/Integration/docker-compose.yml).
 */
class LokiIssuesRegistryTest extends IssuesRegistryTestCase
{
    /** @var resource|null */
    protected static $webhookStubProcess = null;

    protected static string $webhookStubFile = '';

    protected static int $webhookStubPort = 0;

    protected string $lokiUrl = '';

    protected string $runLabel = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled() || static::getLokiUrl() === '') {
            return;
        }
        self::$webhookStubFile = sys_get_temp_dir() . '/ddd-issues-webhook-' . bin2hex(random_bytes(4)) . '.log';
        touch(self::$webhookStubFile);
        self::$webhookStubPort = random_int(38000, 38999);
        self::$webhookStubProcess = proc_open(
            ['php', '-S', '127.0.0.1:' . self::$webhookStubPort, __DIR__ . '/webhook-stub-router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['DDD_TEST_WEBHOOK_STUB_FILE' => self::$webhookStubFile]
        );
        for ($attempt = 0; $attempt < 50 && !@fsockopen('127.0.0.1', self::$webhookStubPort); $attempt++) {
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$webhookStubProcess !== null) {
            proc_terminate(self::$webhookStubProcess);
            proc_close(self::$webhookStubProcess);
            self::$webhookStubProcess = null;
            @unlink(self::$webhookStubFile);
        }
        parent::tearDownAfterClass();
    }

    protected static function getLokiUrl(): string
    {
        return rtrim((string)(getenv('DDD_TEST_LOKI_URL') ?: ($_SERVER['DDD_TEST_LOKI_URL'] ?? '')), '/');
    }

    protected function setUp(): void
    {
        $this->lokiUrl = static::getLokiUrl();
        if ($this->lokiUrl === '') {
            $this->markTestSkipped('Loki end-to-end: start the loki service of tests/Integration/docker-compose.yml and set DDD_TEST_LOKI_URL');
        }
        parent::setUp();
        $this->runLabel = bin2hex(random_bytes(6));
        file_put_contents(self::$webhookStubFile, '');
    }

    protected function createLokiSettings(): IssuesRegistrySettings
    {
        return new IssuesRegistrySettings(
            queryBackend: IssuesRegistrySettings::BACKEND_LOKI,
            queryUrl: $this->lokiUrl,
            querySelector: sprintf('{service="ddd-it", run="%s"}', $this->runLabel),
            alertWebhook: 'http://127.0.0.1:' . self::$webhookStubPort . '/hook',
            alertLinkTemplate: 'https://admin.example/issues/{group_hash}',
            seedHours: 0,
        );
    }

    /**
     * Logs the throwables through the real issues write path into a file and pushes the lines into Loki.
     *
     * @return string[] The JSON lines
     */
    protected function logAndPushLines(Throwable ...$throwables): array
    {
        return $this->logWithIssuesLogServiceAndPushLines(static function (IssuesLogService $issuesLogService) use ($throwables): void {
            foreach ($throwables as $throwable) {
                $issuesLogService->logThrowable($throwable, section: IssuesLogService::LOG_MESSAGE_SECTION_CLI);
            }
        });
    }

    /**
     * Runs the write path into a file and pushes the lines into Loki.
     *
     * @return string[] The JSON lines
     */
    protected function logWithIssuesLogServiceAndPushLines(callable $writeLines): array
    {
        $logFile = sys_get_temp_dir() . '/ddd-issues-loki-' . $this->runLabel . '.log';
        $streamHandler = new StreamHandler($logFile);
        $streamHandler->setFormatter(new ElasticCommonSchemaFormatter());
        IssuesLogService::setSettings(new IssuesLogSettings(serviceName: 'ddd-it', release: 'r-test', workspace: 'mgn'));
        try {
            $issuesLogService = new IssuesLogService(
                new Logger('issues', [$streamHandler]),
                new RequestStack(),
                $this->createMock(RouterInterface::class)
            );
            $writeLines($issuesLogService);
        } finally {
            IssuesLogService::setSettings(null);
        }
        $jsonLines = array_values(array_filter(explode("\n", (string)file_get_contents($logFile))));
        unlink($logFile);
        $values = [];
        foreach ($jsonLines as $lineIndex => $jsonLine) {
            $values[] = [time() . sprintf('%09d', $lineIndex), $jsonLine];
        }
        (new Client())->post($this->lokiUrl . '/loki/api/v1/push', ['json' => ['streams' => [[
            'stream' => ['service' => 'ddd-it', 'run' => $this->runLabel],
            'values' => $values,
        ]]]]);
        return $jsonLines;
    }

    /** Loki ingests asynchronously: wait until the backend sees the expected number of error events */
    protected function waitForLokiEvents(LokiIssuesQueryBackend $lokiIssuesQueryBackend, int $expectedEvents): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $issueHashCounts = $lokiIssuesQueryBackend->aggregateErrors(
                DateTime::fromTimestamp(time() - 600),
                DateTime::fromTimestamp(time() + 600)
            );
            $total = 0;
            foreach ($issueHashCounts->getElements() as $issueHashCount) {
                $total += $issueHashCount->count;
            }
            if ($total >= $expectedEvents) {
                return;
            }
            usleep(200000);
        }
        self::fail(sprintf('Loki did not return %d events', $expectedEvents));
    }

    public function testClientErrorsThroughLokiBecomeAWebGroupWithItsSectionInTheAlert(): void
    {
        $this->logWithIssuesLogServiceAndPushLines(static function (IssuesLogService $issuesLogService): void {
            foreach (['e', 'n'] as $minifiedName) {
                $clientIssueReport = new ClientIssueReport();
                $clientIssueEvent = new ClientIssueEvent();
                $clientIssueEvent->name = 'TypeError';
                $clientIssueEvent->message = $minifiedName . ' is not a function';
                $clientIssueEvent->routePattern = '/[lang]/menu';
                $clientIssueFrame = new ClientIssueFrame();
                $clientIssueFrame->file = '/_next/static/chunks/app/[lang]/menu/page-3f2a9c1b8e7d6f50.js';
                $clientIssueFrame->inApp = true;
                $clientIssueEvent->frames->add($clientIssueFrame);
                $clientIssueReport->events->add($clientIssueEvent);
                $issuesLogService->logClientIssue($clientIssueReport, IssuesLogService::LOG_MESSAGE_SECTION_WEB);
            }
        });
        $issuesRegistrySettings = $this->createLokiSettings();
        $lokiIssuesQueryBackend = new LokiIssuesQueryBackend($issuesRegistrySettings);
        $this->waitForLokiEvents($lokiIssuesQueryBackend, 2);

        $issuesRegistryJobService = new IssuesRegistryJobService();
        $issuesRegistryJobService->setSettings($issuesRegistrySettings);
        $issuesRegistryJobService->setAlertWebhookSender(new IssuesAlertWebhookSender($issuesRegistrySettings));
        $issuesRegistryJobResult = $issuesRegistryJobService->run(DateTime::fromTimestamp(time() + 120));

        self::assertSame(1, $issuesRegistryJobResult->groupsCreated, 'two builds of one bug are one group');
        self::assertSame(2, $issuesRegistryJobResult->eventsCounted);
        $webhookBodies = array_values(array_filter(explode("\n", (string)file_get_contents(self::$webhookStubFile))));
        self::assertStringContainsString(':new: New error [WEB]', json_decode($webhookBodies[0], true)['text']);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var IssueGroupsService $issueGroupsService */
            $issueGroupsService = IssueGroup::getService();
            $issueGroup = $issueGroupsService->listGroups(new IssueGroupsFilter())->first();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        self::assertSame('WEB', $issueGroup->section);
        self::assertSame('TypeError', $issueGroup->exceptionType);
        self::assertContains('WEB:/[lang]/menu', $issueGroup->entryPoints?->getNames() ?? []);
    }

    public function testRealLinesThroughLokiBecomeGroupsAndOneAlertEach(): void
    {
        $issuesFixtureThrower = new IssuesFixtureThrower();
        $this->logAndPushLines(
            $issuesFixtureThrower->runtimeError(1, 'failed for id 42'),
            $issuesFixtureThrower->runtimeError(1, 'failed for id 43'),
            $issuesFixtureThrower->databaseError('42S02'),
        );
        $issuesRegistrySettings = $this->createLokiSettings();
        $lokiIssuesQueryBackend = new LokiIssuesQueryBackend($issuesRegistrySettings);
        $this->waitForLokiEvents($lokiIssuesQueryBackend, 3);
        self::assertTrue($lokiIssuesQueryBackend->probe()->reachable);

        $issuesRegistryJobService = new IssuesRegistryJobService();
        $issuesRegistryJobService->setSettings($issuesRegistrySettings);
        $issuesRegistryJobService->setAlertWebhookSender(new IssuesAlertWebhookSender($issuesRegistrySettings));
        $firstRunResult = $issuesRegistryJobService->run(DateTime::fromTimestamp(time() + 120));
        $secondRunResult = $issuesRegistryJobService->run(DateTime::fromTimestamp(time() + 180));

        self::assertSame(2, $firstRunResult->groupsCreated);
        self::assertSame(3, $firstRunResult->eventsCounted);
        self::assertSame(2, $firstRunResult->alertsSent);
        self::assertSame(0, $secondRunResult->groupsCreated);
        self::assertSame(0, $secondRunResult->alertsSent);
        $webhookBodies = array_values(array_filter(explode("\n", (string)file_get_contents(self::$webhookStubFile))));
        self::assertCount(2, $webhookBodies);
        self::assertStringContainsString(':new: New error', json_decode($webhookBodies[0], true)['text']);

        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var IssueGroupsService $issueGroupsService */
            $issueGroupsService = IssueGroup::getService();
            $issueGroups = $issueGroupsService->listGroups(new IssueGroupsFilter());
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        self::assertSame(2, $issueGroups->count());
        $eventCounts = [];
        foreach ($issueGroups->getElements() as $issueGroup) {
            $eventCounts[$issueGroup->exceptionType] = $issueGroup->eventCount;
            self::assertSame('r-test', $issueGroup->releaseFirstSeen);
            self::assertNotNull($issueGroup->sampleEventId);
        }
        ksort($eventCounts);
        self::assertSame(['PDOException' => 1, 'RuntimeException' => 2], $eventCounts);

        $searchedEvents = $lokiIssuesQueryBackend->searchEvents(new IssueEventSearch(
            DateTime::fromTimestamp(time() - 600),
            DateTime::fromTimestamp(time() + 600),
            text: 'id 43',
        ));
        self::assertSame(1, $searchedEvents->count());
        $workspaceSearch = static fn(string $workspace): int => $lokiIssuesQueryBackend->searchEvents(new IssueEventSearch(
            DateTime::fromTimestamp(time() - 600),
            DateTime::fromTimestamp(time() + 600),
            workspace: $workspace,
        ))->count();
        self::assertSame(3, $workspaceSearch('mgn'));
        self::assertSame(0, $workspaceSearch('ame'));

        $trendFrom = DateTime::fromTimestamp(time() - 600);
        $trendTo = DateTime::fromTimestamp(time() + 60);
        $trendInterval = new TimeInterval($trendFrom, $trendTo);
        $trend = $lokiIssuesQueryBackend->trend($trendInterval, 60);
        self::assertSame(3.0, $trend->getTotal());
        self::assertSame(12, $trend->count());
        self::assertSame(60, $trend->stepSeconds);
        $groupHash = $searchedEvents->first()->groupHash;
        self::assertSame(2.0, $lokiIssuesQueryBackend->trend($trendInterval, 60, $groupHash)->getTotal());
        self::assertStringEndsWith('failed for id 43', (string)$searchedEvents->first()->message);
    }
}
