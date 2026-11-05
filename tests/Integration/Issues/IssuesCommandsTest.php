<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Issues;

use DDD\Domain\Common\Entities\Issues\Client\ClientIssueEvent;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrame;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueReport;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Services\IssuesRegistryJobService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Infrastructure\Logging\Query\IssueEvent;
use DDD\Infrastructure\ObjectStorage\FilesystemObjectStorage;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Services\ObjectStorageService;
use DDD\Infrastructure\Services\SourceMapResolverService;
use DDD\Symfony\Commands\Common\Issues\IssuesHealth;
use Elastic\Monolog\Formatter\ElasticCommonSchemaFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use DDD\Symfony\Commands\Common\Issues\IssuesList;
use DDD\Symfony\Commands\Common\Issues\IssuesResolve;
use DDD\Symfony\Commands\Common\Issues\IssuesShow;
use DDD\Tests\Infrastructure\Logging\SourceMaps\SourceMapFixtures;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The registry commands' JSON contract (what an agent parses) and their exit codes, without a query backend.
 */
class IssuesCommandsTest extends IssuesRegistryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            foreach (['group-a' => 5, 'group-b' => 1] as $groupHash => $eventCount) {
                $issueGroup = new IssueGroup();
                $issueGroup->groupHash = $groupHash;
                $issueGroup->exceptionType = 'RuntimeException';
                $issueGroup->sampleMessage = 'failed in ' . $groupHash;
                $issueGroup->eventCount = $eventCount;
                $issueGroup->isNew = true;
                $issueGroup->registeredAt = DateTime::fromTimestamp(time() - 60);
                $issueGroup->firstSeenAt = DateTime::fromTimestamp(time() - 120);
                $issueGroup->lastSeenAt = DateTime::fromTimestamp(time() - 60 * $eventCount);
                $issueGroup->insert(0);
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function testListAsJson(): void
    {
        $commandTester = new CommandTester(new IssuesList());

        $exitCode = $commandTester->execute(['--status' => 'open', '--new-since' => '1h', '--format' => 'json']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $payload = json_decode($commandTester->getDisplay(), true);
        self::assertSame(2, $payload['count']);
        self::assertSame('group-b', $payload['groups'][0]['groupHash']);
        foreach (['groupHash', 'status', 'exceptionType', 'sampleMessage', 'eventCount', 'firstSeenAt', 'lastSeenAt', 'isNew'] as $field) {
            self::assertArrayHasKey($field, $payload['groups'][0], $field);
        }
    }

    public function testListRejectsAnUnknownStatus(): void
    {
        $commandTester = new CommandTester(new IssuesList());

        self::assertSame(Command::INVALID, $commandTester->execute(['--status' => 'DONE']));
    }

    public function testResolveThenShowAsJson(): void
    {
        $resolveCommandTester = new CommandTester(new IssuesResolve());
        self::assertSame(Command::SUCCESS, $resolveCommandTester->execute(['groupHash' => 'group-a', '--format' => 'json']));
        self::assertSame(['groupHash' => 'group-a', 'environment' => null, 'workspace' => null, 'status' => 'RESOLVED'], json_decode($resolveCommandTester->getDisplay(), true));

        static::entityManager()->clear();
        $showCommandTester = new CommandTester(new IssuesShow());
        self::assertSame(Command::SUCCESS, $showCommandTester->execute(['groupHash' => 'group-a', '--format' => 'json']));
        $payload = json_decode($showCommandTester->getDisplay(), true);
        self::assertSame('RESOLVED', $payload['group']['status']);
        self::assertNotEmpty($payload['group']['resolvedAt']);
        self::assertSame([], $payload['issues']);
        self::assertSame([], $payload['events']);
    }

    public function testUnknownGroupIsInvalid(): void
    {
        $commandTester = new CommandTester(new IssuesShow());

        self::assertSame(Command::INVALID, $commandTester->execute(['groupHash' => 'no-such-group']));
    }

    public function testHealthWithoutABackendIsHealthy(): void
    {
        $commandTester = new CommandTester(new IssuesHealth());

        self::assertSame(Command::SUCCESS, $commandTester->execute(['--format' => 'json']));
        $payload = json_decode($commandTester->getDisplay(), true);
        self::assertTrue($payload['healthy']);
    }

    public function testShowResolvesTheFramesOfAClientEventThroughTheSourceMapStore(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . '/ddd-show-resolve-' . bin2hex(random_bytes(4));
        $chunkUrl = 'https://shop.example.com/_next/static/chunks/app/[lang]/menu/page-3f2a9c1b8e7d6f50.js';
        // a real client line, written by the issues log and read back as the backend returns it
        $logFile = $temporaryDirectory . '/issues.log';
        $streamHandler = new StreamHandler($logFile);
        $streamHandler->setFormatter(new ElasticCommonSchemaFormatter());
        IssuesLogService::setSettings(new IssuesLogSettings(sourceMapsCacheDirectory: $temporaryDirectory . '/cache'));
        $clientIssueReport = new ClientIssueReport();
        $clientIssueReport->client->release = 'web-r7';
        $clientIssueEvent = new ClientIssueEvent();
        $clientIssueEvent->name = 'TypeError';
        $clientIssueEvent->message = 'b.map is not a function';
        $clientIssueFrame = new ClientIssueFrame();
        $clientIssueFrame->file = $chunkUrl;
        $clientIssueFrame->line = 1;
        $clientIssueFrame->column = 35;
        $clientIssueFrame->function = 'a';
        $clientIssueFrame->inApp = true;
        $clientIssueEvent->frames->add($clientIssueFrame);
        $clientIssueReport->events->add($clientIssueEvent);
        (new IssuesLogService(new Logger('issues', [$streamHandler]), new RequestStack(), $this->createMock(RouterInterface::class)))
            ->logClientIssue($clientIssueReport, IssuesLogService::LOG_MESSAGE_SECTION_WEB);
        $issueEvent = IssueEvent::fromJsonLine(trim((string)file_get_contents($logFile)));
        self::assertSame('web-r7', $issueEvent->release);

        $filesystemObjectStorage = new FilesystemObjectStorage($temporaryDirectory . '/store');
        $filesystemObjectStorage->putObject(
            (new SourceMapResolverService())->getSourceMapKey('web-r7', $chunkUrl),
            (string)json_encode(SourceMapFixtures::createMenuPageMap())
        );
        /** @var ObjectStorageService $objectStorageService */
        $objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
        $objectStorageService->setObjectStorage(IssuesLogSettings::DEFAULT_SOURCE_MAPS_STORE, $filesystemObjectStorage);
        $fakeIssuesQueryBackend = new FakeIssuesQueryBackend();
        $fakeIssuesQueryBackend->addIssueEvent(new IssueEvent(
            timestamp: DateTime::fromTimestamp(time() - 30),
            level: 'error',
            eventId: 'client-event-1',
            groupHash: 'group-a',
            release: $issueEvent->release,
            rawJson: $issueEvent->rawJson,
        ));
        /** @var IssuesRegistryJobService $issuesRegistryJobService */
        $issuesRegistryJobService = DDDService::instance()->getService(IssuesRegistryJobService::class);
        $issuesRegistryJobService->setSettings(new IssuesRegistrySettings(queryBackend: IssuesRegistrySettings::BACKEND_LOKI, queryUrl: 'http://fake'));
        $issuesRegistryJobService->setQueryBackend($fakeIssuesQueryBackend);
        try {
            $jsonCommandTester = new CommandTester(new IssuesShow());
            self::assertSame(Command::SUCCESS, $jsonCommandTester->execute(['groupHash' => 'group-a', '--resolve' => true, '--format' => 'json']));
            $eventPayload = json_decode($jsonCommandTester->getDisplay(), true)['events'][0];
            self::assertTrue($eventPayload['resolvedFrames'][0]['resolved']);
            self::assertSame('src/app/[lang]/menu/page.tsx', $eventPayload['resolvedFrames'][0]['originalFile']);
            self::assertSame(3, $eventPayload['resolvedFrames'][0]['originalLine']);

            $textCommandTester = new CommandTester(new IssuesShow());
            $textCommandTester->execute(['groupHash' => 'group-a', '--resolve' => true]);
            self::assertStringContainsString('at map (src/app/[lang]/menu/page.tsx:3:16)', $textCommandTester->getDisplay());
        } finally {
            $issuesRegistryJobService->setSettings(null);
            $objectStorageService->setObjectStorage(IssuesLogSettings::DEFAULT_SOURCE_MAPS_STORE, null);
            IssuesLogService::setSettings(null);
            exec('rm -r ' . escapeshellarg($temporaryDirectory));
        }
    }
}
