<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use DDD\Domain\Common\Entities\Issues\Client\ClientIssueEvent;
use DDD\Domain\Common\Entities\Issues\Client\ClientIssueFrame;
use DDD\Infrastructure\Logging\ClientIssuesFingerprintBuilder;
use DDD\Infrastructure\Logging\IssuesFingerprintBuilder;
use DDD\Infrastructure\Logging\IssuesLogSettings;
use DDD\Infrastructure\Services\IssuesLogService;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

/**
 * The group and issue hashes of existing events never change: a richer event (frames, causes, code context, worker
 * fields) is display-only. Each value was computed before the event-quality changes; a different value splits every
 * existing group in every application's registry.
 */
class IssuesGoldenHashesTest extends TestCase
{
    public const string ENTRY_POINT = 'route:test';

    /** @var array<string, array{0: string, 1: string}> Group hash and issue hash by case */
    public const array GOLDEN_HASHES = [
        'service exception' => ['075bac7c7e53076d783703047a3e15a4', 'da1fb38e12e3151efc054e0b78143c82'],
        'moved line, other message' => ['075bac7c7e53076d783703047a3e15a4', 'da1fb38e12e3151efc054e0b78143c82'],
        'database error' => ['a66f05f150eb437487f79101505dff04', '0bcdf0ed1171bea97d0ce9c1a2f273e7'],
        'engine error' => ['05dec5ada25c9c24334093e231437d70', '6380ee8d585dbd8c4d773a8c6fed958d'],
        'wrapped exception' => ['a462fe358d26cdb93a3f2478b9380dc5', 'dba738cd0e89ce275f6f8c7fd825eaf1'],
    ];

    public const string GOLDEN_CLIENT_GROUP_HASH = '96d9261f17197ebc33f1618b541b6057';

    public const string GOLDEN_CLIENT_ISSUE_HASH = 'b65c730a7cdb96c5bbfb8ccb35adaa6f';

    /** @return array<string, Throwable> */
    protected function createThrowables(): array
    {
        $issuesFixtureThrower = new IssuesFixtureThrower();
        return [
            'service exception' => $issuesFixtureThrower->runtimeError(1),
            'moved line, other message' => $issuesFixtureThrower->runtimeError(2, 'failed for id 43'),
            'database error' => $issuesFixtureThrower->databaseError('42S02'),
            'engine error' => $issuesFixtureThrower->engineError('Undefined array key 7'),
            'wrapped exception' => new LogicException('wrapped', 0, $issuesFixtureThrower->runtimeError(1)),
        ];
    }

    protected function createClientEvent(): ClientIssueEvent
    {
        $clientIssueEvent = new ClientIssueEvent();
        $clientIssueEvent->name = 'TypeError';
        $clientIssueEvent->message = 'e is not a function';
        $clientIssueEvent->routePattern = '/[lang]/menu';
        $clientIssueFrame = new ClientIssueFrame();
        $clientIssueFrame->file = 'https://x.com/_next/static/chunks/app/[lang]/menu/page-3f2a9c1b8e7d6f50.js';
        $clientIssueFrame->line = 1;
        $clientIssueFrame->column = 42;
        $clientIssueFrame->inApp = true;
        $clientIssueEvent->frames->add($clientIssueFrame);
        return $clientIssueEvent;
    }

    public function testTheHashesOfExistingEventsAreUnchanged(): void
    {
        $issuesFingerprintBuilder = new IssuesFingerprintBuilder(dirname(__DIR__, 3));
        $actualHashes = [];
        foreach ($this->createThrowables() as $case => $throwable) {
            $groupHash = $issuesFingerprintBuilder->buildGroupHash($throwable);
            $actualHashes[$case] = [$groupHash, $issuesFingerprintBuilder->buildIssueHash($throwable, $groupHash, self::ENTRY_POINT)];
        }
        $clientIssuesFingerprintBuilder = new ClientIssuesFingerprintBuilder();
        $clientGroupHash = $clientIssuesFingerprintBuilder->buildGroupHash(IssuesLogService::LOG_MESSAGE_SECTION_WEB, $this->createClientEvent());
        $clientIssueHash = $clientIssuesFingerprintBuilder->buildIssueHash($clientGroupHash, $this->createClientEvent());

        $this->assertSame(self::GOLDEN_HASHES, $actualHashes, var_export($actualHashes, true));
        $this->assertSame(self::GOLDEN_CLIENT_GROUP_HASH, $clientGroupHash);
        $this->assertSame(self::GOLDEN_CLIENT_ISSUE_HASH, $clientIssueHash);
    }

    public function testTheLoggedEventCarriesTheSameHashesAsTheBuilder(): void
    {
        IssuesLogService::setSettings(new IssuesLogSettings());
        try {
            $testHandler = new TestHandler();
            $issuesLogService = new IssuesLogService(new Logger('issues', [$testHandler]), new RequestStack(), $this->createMock(RouterInterface::class));
            $throwable = $this->createThrowables()['service exception'];
            $issuesLogService->logThrowable($throwable);

            $context = $testHandler->getRecords()[0]->context;
            $issuesFingerprintBuilder = new IssuesFingerprintBuilder(dirname(__DIR__, 3));
            $this->assertSame($issuesFingerprintBuilder->buildGroupHash($throwable), $context['error.group_hash']);
        } finally {
            IssuesLogService::setSettings(null);
        }
    }
}
