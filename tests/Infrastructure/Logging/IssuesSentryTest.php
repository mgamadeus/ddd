<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Infrastructure\Logging\Query\IssueEventSearch;
use DDD\Infrastructure\Logging\Query\Sentry\SentryIssuesQueryBackend;
use DDD\Infrastructure\Logging\Sentry\IssuesSentryForwarder;
use DDD\Infrastructure\Logging\Sentry\SentryDsn;
use DDD\Infrastructure\Logging\Sentry\SentryEnvelopeSender;
use DDD\Infrastructure\Logging\Sentry\SentryEventBuilder;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Sentry: the DSN, the event built from an issues line, the envelope and its outcomes, the file forwarder's read
 * positions (first sight, partial line, rotation, retry, rejection, threshold) and the query backend's requests.
 */
class IssuesSentryTest extends TestCase
{
    public const string DSN = 'https://publickey123@o42.ingest.sentry.io/4507';

    protected string $temporaryDirectory;

    /** @var array<int, array<string, mixed>> Guzzle history of the mock client */
    protected array $requestHistory = [];

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/ddd-issues-sentry-' . bin2hex(random_bytes(4));
        mkdir($this->temporaryDirectory);
        $this->requestHistory = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->temporaryDirectory);
    }

    protected function createMockClient(Response|ConnectException ...$responses): Client
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->requestHistory));
        return new Client(['handler' => $handlerStack]);
    }

    /** @param array<string, mixed> $overrides */
    protected function buildLine(array $overrides = []): string
    {
        return (string)json_encode($overrides + [
            '@timestamp' => '2026-10-28T10:00:00.123+00:00',
            'log.level' => 'ERROR',
            'message' => 'Exception occurred: failed for id 42',
            'ecs.version' => '8.11.0',
            'log' => ['logger' => 'issues'],
            'event.id' => '0b5f2e4c-1d2a-4f3b-9c8d-7e6f5a4b3c2d',
            'error.group_hash' => 'g1',
            'error.issue_hash' => 'i1',
            'error.entry_point' => 'CLI:app:test',
            'error.type' => 'RuntimeException',
            'error.handled' => true,
            'error.stack_trace' => "#0 /app/src/Service.php(12): App\\Service->run()\n#1 /app/vendor/symfony/console/Command.php(300): App\\Command->execute()\n#2 {main}",
            'log.origin.file.name' => 'Service.php',
            'log.origin.file.line' => 40,
            'service.version' => 'r7',
            'service.environment' => 'prod',
            'host.hostname' => 'pod-1',
            'section' => 'CLI',
            'customer.tier' => 'gold',
        ]);
    }

    public function testDsn(): void
    {
        $sentryDsn = SentryDsn::fromString(self::DSN);
        self::assertSame('https://o42.ingest.sentry.io/api/4507/envelope/', $sentryDsn->getEnvelopeUrl());
        self::assertStringContainsString('sentry_key=publickey123', $sentryDsn->getAuthHeader());
        self::assertSame('http://sentry.local:9000/sentry/api/3/envelope/', SentryDsn::fromString('http://k@sentry.local:9000/sentry/3')->getEnvelopeUrl());
        $this->expectException(InternalErrorException::class);
        SentryDsn::fromString('not a dsn');
    }

    public function testEventFromAnIssuesLine(): void
    {
        $event = json_decode((string)(new SentryEventBuilder())->buildEventJson($this->buildLine()), true);

        self::assertSame('0b5f2e4c1d2a4f3b9c8d7e6f5a4b3c2d', $event['event_id']);
        self::assertSame('error', $event['level']);
        self::assertSame(['g1'], $event['fingerprint']);
        self::assertSame('r7', $event['release']);
        self::assertSame('prod', $event['environment']);
        self::assertSame('pod-1', $event['server_name']);
        self::assertSame(['issue_hash' => 'i1', 'group_hash' => 'g1', 'entry_point' => 'CLI:app:test', 'handled' => 'true', 'section' => 'CLI'], $event['tags']);
        $exception = $event['exception']['values'][0];
        self::assertSame('RuntimeException', $exception['type']);
        self::assertTrue($exception['mechanism']['handled']);
        $frames = $exception['stacktrace']['frames'];
        self::assertSame('{main}', $frames[0]['function']);
        self::assertSame(['filename' => '/app/vendor/symfony/console/Command.php', 'lineno' => 300, 'function' => 'App\\Command->execute', 'in_app' => false], $frames[1]);
        self::assertSame(['filename' => 'Service.php', 'lineno' => 40, 'in_app' => true], end($frames));
        self::assertSame('gold', $event['extra']['customer.tier']);
        self::assertArrayNotHasKey('error.group_hash', $event['extra']);

        $fatalEvent = json_decode((string)(new SentryEventBuilder())->buildEventJson($this->buildLine(['log.level' => 'CRITICAL', 'error.handled' => false])), true);
        self::assertSame('fatal', $fatalEvent['level']);
        self::assertFalse($fatalEvent['exception']['values'][0]['mechanism']['handled']);
        self::assertNull((new SentryEventBuilder())->buildEventJson('not json'));
    }

    public function testEnvelopeAndOutcomes(): void
    {
        $sentryEnvelopeSender = new SentryEnvelopeSender(
            SentryDsn::fromString(self::DSN),
            $this->createMockClient(new Response(200), new Response(429), new Response(400), new Response(503), new ConnectException('down', new Request('POST', '/')))
        );

        self::assertSame(SentryEnvelopeSender::OUTCOME_SENT, $sentryEnvelopeSender->sendEvent('abc', '{"x":1}'));
        self::assertSame(SentryEnvelopeSender::OUTCOME_RETRY, $sentryEnvelopeSender->sendEvent('abc', '{"x":1}'));
        self::assertSame(SentryEnvelopeSender::OUTCOME_REJECTED, $sentryEnvelopeSender->sendEvent('abc', '{"x":1}'));
        self::assertSame(SentryEnvelopeSender::OUTCOME_RETRY, $sentryEnvelopeSender->sendEvent('abc', '{"x":1}'));
        self::assertSame(SentryEnvelopeSender::OUTCOME_RETRY, $sentryEnvelopeSender->sendEvent('abc', '{"x":1}'));
        $request = $this->requestHistory[0]['request'];
        self::assertSame('https://o42.ingest.sentry.io/api/4507/envelope/', (string)$request->getUri());
        self::assertSame('application/x-sentry-envelope', $request->getHeaderLine('Content-Type'));
        $envelopeLines = explode("\n", (string)$request->getBody());
        self::assertSame('abc', json_decode($envelopeLines[0], true)['event_id']);
        self::assertSame(['type' => 'event', 'content_type' => 'application/json', 'length' => 7], json_decode($envelopeLines[1], true));
        self::assertSame('{"x":1}', $envelopeLines[2]);
    }

    protected function createForwarder(Client $client, string $minimumLevel = 'error'): IssuesSentryForwarder
    {
        return new IssuesSentryForwarder(
            new SentryEnvelopeSender(SentryDsn::fromString(self::DSN), $client),
            $this->temporaryDirectory . '/.state.json',
            $minimumLevel
        );
    }

    public function testForwarderReadPositions(): void
    {
        $logFile = $this->temporaryDirectory . '/issues_prod.log';
        file_put_contents($logFile, $this->buildLine() . "\n");
        $issuesSentryForwarder = $this->createForwarder($this->createMockClient(...array_fill(0, 10, new Response(200))));

        // first sight: from the end, the history is not forwarded
        self::assertSame(0, $issuesSentryForwarder->forwardFiles(false, $logFile)->eventsSent);

        // new lines: an error, a warning (below the threshold), an uncaught warning, a partial line
        file_put_contents($logFile, $this->buildLine() . "\n"
            . $this->buildLine(['log.level' => 'WARNING']) . "\n"
            . $this->buildLine(['log.level' => 'WARNING', 'error.handled' => false]) . "\n"
            . substr($this->buildLine(), 0, 40), FILE_APPEND);
        $forwardResult = $issuesSentryForwarder->forwardFiles(false, $logFile);
        self::assertSame(2, $forwardResult->eventsSent);
        self::assertSame(1, $forwardResult->linesSkipped);

        // the partial line completes
        file_put_contents($logFile, substr($this->buildLine(), 40) . "\n", FILE_APPEND);
        self::assertSame(1, $issuesSentryForwarder->forwardFiles(false, $logFile)->eventsSent);

        // rotation: a new file under the same name starts at 0
        unlink($logFile);
        file_put_contents($logFile, $this->buildLine() . "\n");
        self::assertSame(1, $issuesSentryForwarder->forwardFiles(false, $logFile)->eventsSent);
        self::assertCount(4, $this->requestHistory);
    }

    public function testForwarderStopsForRetryAndResumes(): void
    {
        $logFile = $this->temporaryDirectory . '/issues_prod.log';
        file_put_contents($logFile, str_repeat($this->buildLine() . "\n", 3));
        $issuesSentryForwarder = $this->createForwarder($this->createMockClient(
            new Response(200),
            new Response(429),
            new Response(400),
            new Response(200),
        ));

        $retryResult = $issuesSentryForwarder->forwardFiles(true, $logFile);
        self::assertSame(1, $retryResult->eventsSent);
        self::assertTrue($retryResult->stoppedForRetry);

        $resumeResult = $issuesSentryForwarder->forwardFiles(true, $logFile);
        self::assertSame(1, $resumeResult->eventsRejected);
        self::assertSame(1, $resumeResult->eventsSent);
        self::assertFalse($resumeResult->stoppedForRetry);
    }

    public function testSentryQueryBackendRequestsAndPaging(): void
    {
        $issuesRegistrySettings = new IssuesRegistrySettings(
            queryBackend: IssuesRegistrySettings::BACKEND_SENTRY,
            queryToken: 'tok',
            querySelector: 'acme/backend',
        );
        $sentryIssuesQueryBackend = new SentryIssuesQueryBackend($issuesRegistrySettings, $this->createMockClient(
            new Response(200, ['Link' => '<https://sentry.io/x>; rel="previous"; results="false"; cursor="0:0:1", <https://sentry.io/x>; rel="next"; results="true"; cursor="0:100:0"'], (string)json_encode(['data' => [
                ['tags[group_hash]' => 'g1', 'tags[issue_hash]' => 'i1', 'tags[entry_point]' => 'CLI:a', 'count()' => 7],
            ]])),
            new Response(200, ['Link' => '<https://sentry.io/x>; rel="next"; results="false"; cursor="0:200:0"'], (string)json_encode(['data' => [
                ['tags[group_hash]' => 'g2', 'tags[issue_hash]' => '', 'tags[entry_point]' => '', 'count()' => 1],
            ]])),
            new Response(200, [], (string)json_encode(['data' => [[
                'id' => 'e1', 'timestamp' => '2026-10-28T10:00:00+00:00', 'message' => 'boom', 'level' => 'error',
                'error.type' => ['RuntimeException'], 'tags[group_hash]' => 'g1', 'tags[route]' => 'api_x', 'release' => 'r7',
            ]]])),
        ));

        $issueHashCounts = $sentryIssuesQueryBackend->aggregateErrors(DateTime::fromTimestamp(1761645600), DateTime::fromTimestamp(1761649200));
        $issueEvents = $sentryIssuesQueryBackend->searchEvents(new IssueEventSearch(
            DateTime::fromTimestamp(1761645600),
            DateTime::fromTimestamp(1761649200),
            text: 'boom',
            groupHash: 'g1',
            limit: 5,
        ));

        self::assertSame(['g1', 'g2'], $issueHashCounts->getGroupHashes());
        self::assertSame(7, $issueHashCounts->getCountForGroupHash('g1'));
        $firstRequest = $this->requestHistory[0]['request'];
        self::assertSame('/api/0/organizations/acme/events/', $firstRequest->getUri()->getPath());
        self::assertSame('Bearer tok', $firstRequest->getHeaderLine('Authorization'));
        self::assertStringContainsString('project:backend has:group_hash', urldecode($firstRequest->getUri()->getQuery()));
        self::assertStringContainsString('cursor=0%3A100%3A0', $this->requestHistory[1]['request']->getUri()->getQuery());
        self::assertSame(1, $issueEvents->count());
        $issueEvent = $issueEvents->first();
        self::assertSame('RuntimeException', $issueEvent->exceptionType);
        self::assertSame('api_x', $issueEvent->routeName);
        self::assertSame('e1', $issueEvent->eventId);
        $searchQuery = urldecode($this->requestHistory[2]['request']->getUri()->getQuery());
        self::assertStringContainsString('group_hash:"g1"', $searchQuery);
        self::assertStringContainsString('"boom"', $searchQuery);
        self::assertStringContainsString('per_page=5', $searchQuery);
    }

    public function testSentryTrendFromEventsStats(): void
    {
        $issuesRegistrySettings = new IssuesRegistrySettings(
            queryBackend: IssuesRegistrySettings::BACKEND_SENTRY,
            queryToken: 'tok',
            querySelector: 'acme/backend',
        );
        $sentryIssuesQueryBackend = new SentryIssuesQueryBackend($issuesRegistrySettings, $this->createMockClient(
            new Response(200, [], (string)json_encode(['data' => [
                [1761642000, [['count' => 3]]],
                [1761645600, [['count' => 0]]],
                [1761649200, [['count' => 5]]],
            ]])),
        ));

        $issueTrend = $sentryIssuesQueryBackend->trend(
            DateTime::fromTimestamp(1761642000),
            DateTime::fromTimestamp(1761649200 + 1800),
            3600,
            'g1'
        );

        self::assertSame([1761642000 => 3, 1761645600 => 0, 1761649200 => 5], $issueTrend->getCountsByBucketStart());
        $request = $this->requestHistory[0]['request'];
        self::assertSame('/api/0/organizations/acme/events-stats/', $request->getUri()->getPath());
        $query = urldecode($request->getUri()->getQuery());
        self::assertStringContainsString('interval=1h', $query);
        self::assertStringContainsString('yAxis=count()', $query);
        self::assertStringContainsString('group_hash:"g1"', $query);
    }
}
