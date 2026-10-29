<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use DDD\Domain\Common\Entities\Issues\Client\ClientIssueBreadcrumb;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueEvent;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrame;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueIngestResult;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueReport;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueTag;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Logging\ClientIssuesFingerprintBuilder;
use DDD\Infrastructure\Logging\ClientUserAgentParser;
use DDD\Infrastructure\Logging\IssuesLogRedactor;
use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Symfony\Commands\Common\Issues\IssuesTest;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

/**
 * A browser, Node or React Native client reports errors that become ordinary issues events of their own section.
 */
class ClientIssuesIngestTest extends TestCase
{
    public const string IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';

    protected TestHandler $testHandler;

    protected function setUp(): void
    {
        $this->testHandler = new TestHandler();
        IssuesLogService::setSettings(new IssuesLogSettings());
    }

    protected function tearDown(): void
    {
        IssuesLogService::setSettings(null);
    }

    protected function createIssuesLogService(?Request $request = null): IssuesLogService
    {
        $requestStack = new RequestStack();
        $requestStack->push($request ?? $this->createIngestRequest());
        return new IssuesLogService(new Logger('issues', [$this->testHandler]), $requestStack, $this->createMock(RouterInterface::class));
    }

    protected function createIngestRequest(string $userAgent = self::IPHONE_SAFARI): Request
    {
        $request = Request::create('/api/public/common/issues/report?debug=1', 'POST', content: '{"secret":"body"}');
        $request->headers->set('User-Agent', $userAgent);
        $request->headers->set('X-Forwarded-For', '203.0.113.9');
        $request->attributes->set('_route', 'public_issues_report');
        return $request;
    }

    protected function createEvent(string $name = 'TypeError', string $message = 'e is not a function'): ClientIssueEvent
    {
        $clientIssueEvent = new ClientIssueEvent();
        $clientIssueEvent->occurredAt = '2026-10-29T10:00:00Z';
        $clientIssueEvent->name = $name;
        $clientIssueEvent->message = $message;
        $clientIssueEvent->routePattern = '/[lang]/menu/[locationSlug]';
        $clientIssueEvent->url = 'https://dineful.com/de/menu/pizza-roma?invitationToken=abc123&tab=2';
        $innerFrame = new ClientIssueFrame();
        $innerFrame->file = 'https://dineful.com/_next/static/chunks/framework-0a1b2c3d4e5f6a7b.js';
        $innerFrame->line = 1;
        $innerFrame->column = 900;
        $innerFrame->function = 'n';
        $clientIssueEvent->frames->add($innerFrame);
        $appFrame = new ClientIssueFrame();
        $appFrame->file = 'https://dineful.com/_next/static/chunks/app/[lang]/menu/[locationSlug]/page-3f2a9c1b8e7d6f50.js';
        $appFrame->line = 1;
        $appFrame->column = 4242;
        $appFrame->function = 'onClick';
        $appFrame->inApp = true;
        $clientIssueEvent->frames->add($appFrame);
        return $clientIssueEvent;
    }

    protected function createReport(ClientIssueEvent ...$clientIssueEvents): ClientIssueReport
    {
        $clientIssueReport = new ClientIssueReport();
        $clientIssueReport->client->release = 'web-2026.10.29';
        $clientIssueReport->client->tabId = 'tab-7f3a';
        $clientIssueReport->client->viewportWidth = 390;
        $clientIssueReport->client->viewportHeight = 844;
        foreach ($clientIssueEvents as $clientIssueEvent) {
            $clientIssueReport->events->add($clientIssueEvent);
        }
        return $clientIssueReport;
    }

    public function testAClientEventBecomesAWebLineWithStableHashes(): void
    {
        $issuesLogService = $this->createIssuesLogService();

        $firstResult = $issuesLogService->logClientIssue($this->createReport($this->createEvent()), IssuesLogService::LOG_MESSAGE_SECTION_WEB);
        $issuesLogService->logClientIssue($this->createReport($this->createEvent()), IssuesLogService::LOG_MESSAGE_SECTION_WEB);

        $this->assertSame(1, $firstResult->accepted);
        [$firstRecord, $secondRecord] = $this->testHandler->getRecords();
        $context = $firstRecord->context;
        $this->assertSame('ERROR', $firstRecord->level->getName());
        $this->assertSame('TypeError: e is not a function', $firstRecord->message);
        $this->assertSame('WEB', $context['section']);
        $this->assertSame($context['error.group_hash'], $secondRecord->context['error.group_hash']);
        $this->assertNotSame($context['event.id'], $secondRecord->context['event.id']);
        $this->assertSame('WEB:/[lang]/menu/[locationSlug]', $context['error.entry_point']);
        $this->assertSame('/[lang]/menu/[locationSlug]', $context['route_name'], 'the client route, not the ingest route');
        $this->assertFalse($context['error.handled']);
        $this->assertSame('error', $context['error.kind']);
        $this->assertSame('app/[lang]/menu/[locationSlug]/page', $context['log.origin.file.name']);
        $this->assertStringContainsString('at onClick (https://dineful.com/_next/static/chunks/app/[lang]/menu/[locationSlug]/page-3f2a9c1b8e7d6f50.js:1:4242)', $context['error.stack_trace']);
        $this->assertSame('web-2026.10.29', $context['service.version']);
        $this->assertSame('tab-7f3a', $context['client.tab_id'], 'not redacted like a session');
        $this->assertSame('390x844', $context['client.viewport']);
        $this->assertCount(2, $context['client.frames']);
        $this->assertSame('https://dineful.com/de/menu/pizza-roma?invitationToken=[REDACTED]&tab=2', $context['url.original']);
        $this->assertSame('/de/menu/pizza-roma', $context['url.path']);
        $this->assertSame('Safari', $context['user_agent.name']);
        $this->assertSame('18', $context['user_agent.version']);
        $this->assertSame('iOS', $context['user_agent.os.name']);
        foreach (IssuesLogService::INGEST_REQUEST_CONTEXT_KEYS as $ingestRequestContextKey) {
            $this->assertArrayNotHasKey($ingestRequestContextKey, $context);
        }
        $this->assertArrayNotHasKey('http.request.method', $context, 'the report\'s POST is not the client\'s request');
    }

    public function testIgnoredMessagesAreDroppedAndCounted(): void
    {
        $clientIssueEvents = [];
        for ($eventIndex = 0; $eventIndex < 7; $eventIndex++) {
            $clientIssueEvents[] = $this->createEvent('TypeError', "failure $eventIndex");
        }
        $clientIssueEvents[] = $this->createEvent('ChunkLoadError', 'Loading chunk 412 failed.');
        $clientIssueEvents[] = $this->createEvent('Error', 'ResizeObserver loop completed with undelivered notifications.');
        $clientIssueEvents[] = $this->createEvent('Error', 'Script error.');

        $clientIssueIngestResult = $this->createIssuesLogService()
            ->logClientIssue($this->createReport(...$clientIssueEvents), IssuesLogService::LOG_MESSAGE_SECTION_WEB);

        $this->assertSame(7, $clientIssueIngestResult->accepted);
        $this->assertSame(3, $clientIssueIngestResult->dropped);
        $this->assertSame(array_fill(0, 3, ClientIssueIngestResult::DROP_REASON_IGNORED_MESSAGE), $clientIssueIngestResult->dropReasons);
        $this->assertCount(7, $this->testHandler->getRecords());
    }

    public function testCrawlersTooManyEventsAndTheKillSwitchDrop(): void
    {
        $crawlerResult = $this->createIssuesLogService($this->createIngestRequest('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'))
            ->logClientIssue($this->createReport($this->createEvent()), IssuesLogService::LOG_MESSAGE_SECTION_WEB);
        $this->assertSame([ClientIssueIngestResult::DROP_REASON_IGNORED_USER_AGENT], $crawlerResult->dropReasons);

        $twelveEvents = array_map(fn(int $eventIndex): ClientIssueEvent => $this->createEvent('TypeError', "failure $eventIndex"), range(1, 12));
        $tooManyResult = $this->createIssuesLogService()->logClientIssue($this->createReport(...$twelveEvents), IssuesLogService::LOG_MESSAGE_SECTION_WEB);
        $this->assertSame(10, $tooManyResult->accepted);
        $this->assertSame([ClientIssueIngestResult::DROP_REASON_TOO_MANY_EVENTS, ClientIssueIngestResult::DROP_REASON_TOO_MANY_EVENTS], $tooManyResult->dropReasons);

        IssuesLogService::setSettings(new IssuesLogSettings(clientIngestEnabled: false));
        $disabledResult = $this->createIssuesLogService()->logClientIssue($this->createReport($this->createEvent()), IssuesLogService::LOG_MESSAGE_SECTION_WEB);
        $this->assertSame(0, $disabledResult->accepted);
        $this->assertSame([ClientIssueIngestResult::DROP_REASON_INGEST_DISABLED], $disabledResult->dropReasons);
    }

    public function testAnEmptyReportAndABackendSectionAreRefused(): void
    {
        $issuesLogService = $this->createIssuesLogService();
        try {
            $issuesLogService->logClientIssue($this->createReport(), IssuesLogService::LOG_MESSAGE_SECTION_WEB);
            $this->fail('An empty report must be refused');
        } catch (BadRequestException) {
        }
        $this->expectException(InternalErrorException::class);
        $issuesLogService->logClientIssue($this->createReport($this->createEvent()), IssuesLogService::LOG_MESSAGE_SECTION_HTTP);
    }

    public function testTheCapsOfTheWireContractAreEnforced(): void
    {
        $clientIssueEvent = $this->createEvent('TypeError', str_repeat('x', 5000));
        $clientIssueEvent->kind = 'something-else';
        for ($frameIndex = 0; $frameIndex < 40; $frameIndex++) {
            $clientIssueFrame = new ClientIssueFrame();
            $clientIssueFrame->file = 'https://cdn.example/lib.js';
            $clientIssueEvent->frames->add($clientIssueFrame);
        }
        for ($tagIndex = 0; $tagIndex < 15; $tagIndex++) {
            $clientIssueTag = new ClientIssueTag();
            $clientIssueTag->key = "tag$tagIndex";
            $clientIssueTag->value = str_repeat('v', 100);
            $clientIssueEvent->tags->add($clientIssueTag);
        }

        $this->createIssuesLogService()->logClientIssue($this->createReport($clientIssueEvent), IssuesLogService::LOG_MESSAGE_SECTION_WEB_SERVER);

        $context = $this->testHandler->getRecords()[0]->context;
        $this->assertSame('WEB_SERVER', $context['section']);
        $this->assertSame('error', $context['error.kind'], 'an unknown kind falls back');
        $this->assertCount(30, $context['client.frames']);
        $this->assertCount(10, $context['client.tags']);
        $this->assertSame(64, strlen($context['client.tags']['tag0']));
        $this->assertLessThanOrEqual(strlen('TypeError: ') + 1024, strlen($this->testHandler->getRecords()[0]->message));
    }

    public function testBreadcrumbsAreCappedBelowTheWebLimitOfTheFileSink(): void
    {
        IssuesLogService::setSettings(new IssuesLogSettings(target: IssuesLogSettings::TARGET_FILE));
        $clientIssueEvent = $this->createEvent();
        for ($breadcrumbIndex = 0; $breadcrumbIndex < 160; $breadcrumbIndex++) {
            $clientIssueBreadcrumb = new ClientIssueBreadcrumb();
            $clientIssueBreadcrumb->at = '2026-10-29T09:59:00Z';
            $clientIssueBreadcrumb->category = ClientIssueBreadcrumb::CATEGORY_CONSOLE;
            $clientIssueBreadcrumb->message = "breadcrumb $breadcrumbIndex " . str_repeat('y', 240);
            $clientIssueBreadcrumb->url = 'https://dineful.com/de/menu/' . str_repeat('z', 700);
            $clientIssueEvent->breadcrumbs->add($clientIssueBreadcrumb);
        }

        $this->createIssuesLogService()->logClientIssue($this->createReport($clientIssueEvent), IssuesLogService::LOG_MESSAGE_SECTION_WEB);

        $record = $this->testHandler->getRecords()[0];
        $eventBytes = strlen($record->message) + strlen((string)json_encode($record->context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->assertLessThanOrEqual(IssuesLogSettings::DEFAULT_MAX_EVENT_BYTES_WEB, $eventBytes);
        $this->assertGreaterThan(IssuesLogSettings::DEFAULT_MAX_EVENT_BYTES, $eventBytes, 'the file sink keeps 16 KB for client events');
        $this->assertNotEmpty($record->context['client.breadcrumbs']);
        $breadcrumbs = $record->context['client.breadcrumbs'];
        $this->assertStringStartsWith('breadcrumb 159 ', $breadcrumbs[array_key_last($breadcrumbs)]['message'], 'the newest breadcrumbs stay');
    }

    public function testTheGroupHashIsBuildIndependent(): void
    {
        $clientIssuesFingerprintBuilder = new ClientIssuesFingerprintBuilder();
        $firstBuildEvent = $this->createEvent('TypeError', 'e is not a function');
        $secondBuildEvent = $this->createEvent('TypeError', 'n is not a function');
        $secondBuildEvent->frames->getFirstInAppFrame()->file = 'https://dineful.com/_next/static/chunks/app/[lang]/menu/[locationSlug]/page-99aa88bb77cc66dd.js';
        $secondBuildEvent->frames->getFirstInAppFrame()->column = 17;

        $this->assertSame(
            $clientIssuesFingerprintBuilder->buildGroupHash('WEB', $firstBuildEvent),
            $clientIssuesFingerprintBuilder->buildGroupHash('WEB', $secondBuildEvent)
        );
        $this->assertNotSame(
            $clientIssuesFingerprintBuilder->buildGroupHash('WEB', $firstBuildEvent),
            $clientIssuesFingerprintBuilder->buildGroupHash('WEB_SERVER', $firstBuildEvent)
        );
        $this->assertSame('{ident}.map is not a function', $clientIssuesFingerprintBuilder->normaliseMessage('n.map is not a function'));
        $this->assertSame('items.map is not a function', $clientIssuesFingerprintBuilder->normaliseMessage('items.map is not a function'));
        $this->assertSame(
            "Cannot read properties of undefined (reading 'price2')",
            $clientIssuesFingerprintBuilder->normaliseMessage("Cannot read properties of undefined (reading 'price2')")
        );
        $this->assertSame(
            'Minified React error #418; visit {url} for the full message',
            $clientIssuesFingerprintBuilder->normaliseMessage('Minified React error #418; visit https://react.dev/errors/418?args[]=x for the full message')
        );
        $this->assertSame(
            'Order {uuid} failed after {number} ms at {timestamp}',
            $clientIssuesFingerprintBuilder->normaliseMessage('Order 0b9d3c3e-6f4a-4b8e-9c1d-2a3b4c5d6e7f failed after 1500 ms at 2026-10-29T10:00:00Z')
        );
        $this->assertSame('app/[lang]/menu/[locationSlug]/page', $clientIssuesFingerprintBuilder->normaliseFilePath(
            'https://dineful.com/_next/static/chunks/app/[lang]/menu/[locationSlug]/page-3f2a9c1b8e7d6f50.js?dpl=abc'
        ));
    }

    public function testClientValuesAreRedacted(): void
    {
        $issuesLogRedactor = new IssuesLogRedactor();

        $this->assertSame(
            'https://dineful.com/de/invitations/[REDACTED]/accept?resetToken=[REDACTED]',
            $issuesLogRedactor->redactUrl('https://dineful.com/de/invitations/q8Zk2LmT/accept?resetToken=s3cr3t')
        );
        $this->assertSame(
            '/api/public/reservations/[UUID]/cancel',
            $issuesLogRedactor->redactUrl('/api/public/reservations/0b9d3c3e-6f4a-4b8e-9c1d-2a3b4c5d6e7f/cancel')
        );
        $this->assertSame('/orders/[HEX]', $issuesLogRedactor->redactUrl('/orders/9f86d081884c7d659a2feaa0c55ad015'));
        $this->assertSame(
            'call [PHONE] or mail [EMAIL], token [JWT], booked on 2026-10-29',
            $issuesLogRedactor->sanitizeClientText('call +49 151 2345 6789 or mail anna@example.com, token eyJhbGciOiJI.eyJzdWIiOiIx.SflKxwRJSMeK, booked on 2026-10-29')
        );
    }

    public function testTheUserAgentParserTellsBrowsersApart(): void
    {
        $clientUserAgentParser = new ClientUserAgentParser();
        $chromeOnWindows = $clientUserAgentParser->parseUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36');
        $edgeOnMac = $clientUserAgentParser->parseUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.2849.56');
        $firefoxOnAndroid = $clientUserAgentParser->parseUserAgent('Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0');

        $this->assertSame(['Chrome', '130', 'Windows'], [$chromeOnWindows->name, $chromeOnWindows->version, $chromeOnWindows->osName]);
        $this->assertSame(['Edge', '130', 'macOS'], [$edgeOnMac->name, $edgeOnMac->version, $edgeOnMac->osName]);
        $this->assertSame(['Firefox', '131', 'Android'], [$firefoxOnAndroid->name, $firefoxOnAndroid->version, $firefoxOnAndroid->osName]);
        $this->assertNull($clientUserAgentParser->parseUserAgent(null)->name);
    }

    public function testAManualWarningIsHandledAndAWarning(): void
    {
        $clientIssueEvent = $this->createEvent('Warning', 'payment widget slow');
        $clientIssueEvent->kind = ClientIssueEvent::KIND_MANUAL;
        $clientIssueEvent->level = ClientIssueEvent::LEVEL_WARNING;

        $this->createIssuesLogService()->logClientIssue($this->createReport($clientIssueEvent), IssuesLogService::LOG_MESSAGE_SECTION_WEB);

        $record = $this->testHandler->getRecords()[0];
        $this->assertSame('WARNING', $record->level->getName());
        $this->assertTrue($record->context['error.handled']);
    }

    public function testTheTestCommandLogsOneWebEventWithAStableGroupHash(): void
    {
        $firstCommandTester = new CommandTester(new IssuesTest($this->createIssuesLogService()));
        $secondCommandTester = new CommandTester(new IssuesTest($this->createIssuesLogService()));

        $this->assertSame(Command::SUCCESS, $firstCommandTester->execute(['--client' => true]));
        $this->assertSame(Command::SUCCESS, $secondCommandTester->execute(['--client' => true]));

        [$firstRecord, $secondRecord] = $this->testHandler->getRecords();
        $this->assertSame('WEB', $firstRecord->context['section']);
        $this->assertSame($firstRecord->context['error.group_hash'], $secondRecord->context['error.group_hash']);
        $this->assertStringContainsString('error.group_hash: ' . $firstRecord->context['error.group_hash'], $firstCommandTester->getDisplay());
        $this->assertStringContainsString('accepted: 1, dropped: 0', $firstCommandTester->getDisplay());
    }
}
