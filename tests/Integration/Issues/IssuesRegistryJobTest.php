<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Issues;

use DDD\Domain\Common\Entities\Issues\Issue;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssuesJobState;
use DDD\Domain\Common\Services\IssueGroupsService;
use DDD\Domain\Common\Services\IssuesJobStatesService;
use DDD\Domain\Common\Services\IssuesRegistryJobService;
use DDD\Domain\Common\Services\IssuesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Common\Issues\IssuesHealth;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The issues registry job against the registry tables on both engines, with an in-memory log backend and a recording
 * webhook: seed mode, one group / one alert across runs, late lines, regressions only after resolvedAt, the explosion
 * guard, storms above the raw line cap, an outage caught up, failed alerts retried, health.
 */
class IssuesRegistryJobTest extends IssuesRegistryTestCase
{
    protected FakeIssuesQueryBackend $fakeIssuesQueryBackend;

    protected RecordingIssuesAlertWebhookSender $recordingIssuesAlertWebhookSender;

    protected int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = time();
        $this->fakeIssuesQueryBackend = new FakeIssuesQueryBackend();
    }

    protected function createJobService(int $seedHours = 0, int $groupExplosionLimit = 50): IssuesRegistryJobService
    {
        $issuesRegistrySettings = new IssuesRegistrySettings(
            queryBackend: IssuesRegistrySettings::BACKEND_LOKI,
            alertWebhook: 'http://webhook.invalid/hook',
            alertLinkTemplate: 'https://admin.example/issues/{group_hash}',
            seedHours: $seedHours,
            groupExplosionLimit: $groupExplosionLimit,
        );
        $this->recordingIssuesAlertWebhookSender = new RecordingIssuesAlertWebhookSender($issuesRegistrySettings);
        $issuesRegistryJobService = new IssuesRegistryJobService();
        $issuesRegistryJobService->setSettings($issuesRegistrySettings);
        $issuesRegistryJobService->setQueryBackend($this->fakeIssuesQueryBackend);
        $issuesRegistryJobService->setAlertWebhookSender($this->recordingIssuesAlertWebhookSender);
        return $issuesRegistryJobService;
    }

    protected function secondsFromNow(int $offsetSeconds): DateTime
    {
        return DateTime::fromTimestamp($this->now + $offsetSeconds);
    }

    public function testSeedModeRegistersGroupsWithoutAlerts(): void
    {
        $issuesRegistryJobService = $this->createJobService(seedHours: 24);
        $this->fakeIssuesQueryBackend->addEvent($this->now - 600, 'group-a');

        $issuesRegistryJobResult = $issuesRegistryJobService->run($this->secondsFromNow(0));

        self::assertTrue($issuesRegistryJobResult->seedMode);
        self::assertSame(1, $issuesRegistryJobResult->groupsCreated);
        self::assertSame([], $this->recordingIssuesAlertWebhookSender->sentTexts);
        $issueGroup = $this->findGroup('group-a');
        self::assertFalse($issueGroup->isNew);
        self::assertFalse($issueGroup->alertPending);
    }

    public function testSeedOnDemandFromTheCommandLine(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        $issuesRegistryJobService->run($this->secondsFromNow(0));
        $this->fakeIssuesQueryBackend->addEvent($this->now + 100, 'group-a');

        $issuesRegistryJobResult = $issuesRegistryJobService->run($this->secondsFromNow(300), seedHours: 2);

        self::assertTrue($issuesRegistryJobResult->seedMode);
        self::assertSame([], $this->recordingIssuesAlertWebhookSender->sentTexts);
    }

    public function testTwoRunsGiveOneGroupAndOneAlert(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        $this->fakeIssuesQueryBackend->addEvent($this->now - 600, 'group-a', entryPoint: 'route:a');
        $this->fakeIssuesQueryBackend->addEvent($this->now - 500, 'group-a', entryPoint: 'command:b');

        $firstRunResult = $issuesRegistryJobService->run($this->secondsFromNow(0));
        $this->fakeIssuesQueryBackend->addEvent($this->now + 30, 'group-a', entryPoint: 'route:a');
        $secondRunResult = $issuesRegistryJobService->run($this->secondsFromNow(120));

        self::assertSame(1, $firstRunResult->groupsCreated);
        self::assertSame(2, $firstRunResult->issuesCreated);
        self::assertSame(0, $secondRunResult->groupsCreated);
        self::assertCount(1, $this->recordingIssuesAlertWebhookSender->sentTexts);
        self::assertStringContainsString(':new: New error', $this->recordingIssuesAlertWebhookSender->sentTexts[0]);
        self::assertStringContainsString('https://admin.example/issues/group-a', $this->recordingIssuesAlertWebhookSender->sentTexts[0]);
        $issueGroup = $this->findGroup('group-a');
        self::assertSame(3, $issueGroup->eventCount);
        self::assertTrue($issueGroup->isNew);
        self::assertFalse($issueGroup->alertPending);
        self::assertNotNull($issueGroup->alertSentAt);
        self::assertEqualsCanonicalizing(['route:a', 'command:b'], $issueGroup->entryPoints->getNames());
        self::assertSame($this->now - 600, $issueGroup->firstSeenAt->getTimestamp());
        self::assertSame($this->now + 30, $issueGroup->lastSeenAt->getTimestamp());
    }

    public function testLateLinesBehindTheWatermarkAreDiscovered(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        $issuesRegistryJobService->run($this->secondsFromNow(0));
        // a collector replays its buffer: the line carries its original timestamp, long behind the watermark
        $this->fakeIssuesQueryBackend->addEvent($this->now - 3000, 'group-late');

        $issuesRegistryJobResult = $issuesRegistryJobService->run($this->secondsFromNow(60));

        self::assertSame(1, $issuesRegistryJobResult->groupsCreated);
        self::assertNotNull($this->findGroup('group-late'));
        self::assertCount(1, $this->recordingIssuesAlertWebhookSender->sentTexts);
    }

    public function testRegressionOnlyForAnEventAfterTheResolution(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        $this->fakeIssuesQueryBackend->addEvent($this->now - 600, 'group-a');
        $issuesRegistryJobService->run($this->secondsFromNow(0));
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var IssueGroupsService $issueGroupsService */
            $issueGroupsService = IssueGroup::getService();
            $issueGroupsService->resolve($this->findGroup('group-a'));
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $resolvedAt = $this->findGroup('group-a')->resolvedAt->getTimestamp();

        // a replay of lines from before the fix does not reopen it
        $this->fakeIssuesQueryBackend->addEvent($resolvedAt - 100, 'group-a');
        $replayRunResult = $issuesRegistryJobService->run($this->secondsFromNow(120));
        self::assertSame(0, $replayRunResult->regressions);
        self::assertSame(IssueGroup::STATUS_RESOLVED, $this->findGroup('group-a')->status);

        $this->fakeIssuesQueryBackend->addEvent($resolvedAt + 30, 'group-a');
        $regressionRunResult = $issuesRegistryJobService->run(DateTime::fromTimestamp($resolvedAt + 200));
        self::assertSame(1, $regressionRunResult->regressions);
        $issueGroup = $this->findGroup('group-a');
        self::assertSame(IssueGroup::STATUS_OPEN, $issueGroup->status);
        self::assertTrue($issueGroup->isRegression);
        self::assertCount(2, $this->recordingIssuesAlertWebhookSender->sentTexts);
        self::assertStringContainsString(':repeat: Regression', $this->recordingIssuesAlertWebhookSender->sentTexts[1]);
    }

    public function testIgnoredGroupNeverAlerts(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        $this->recordingIssuesAlertWebhookSender->failSends = true;
        $this->fakeIssuesQueryBackend->addEvent($this->now - 600, 'group-a');
        $issuesRegistryJobService->run($this->secondsFromNow(0));
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var IssueGroupsService $issueGroupsService */
            $issueGroupsService = IssueGroup::getService();
            $issueGroupsService->ignore($this->findGroup('group-a'));
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $this->recordingIssuesAlertWebhookSender->failSends = false;
        $this->fakeIssuesQueryBackend->addEvent($this->now + 30, 'group-a');

        $issuesRegistryJobService->run($this->secondsFromNow(120));

        self::assertSame([], $this->recordingIssuesAlertWebhookSender->sentTexts);
        self::assertSame(IssueGroup::STATUS_IGNORED, $this->findGroup('group-a')->status);
    }

    public function testExplosionGuardSendsOneMessageInsteadOfOnePerGroup(): void
    {
        $issuesRegistryJobService = $this->createJobService(groupExplosionLimit: 2);
        foreach (['group-a', 'group-b', 'group-c'] as $groupHash) {
            $this->fakeIssuesQueryBackend->addEvent($this->now - 600, $groupHash);
        }

        $firstRunResult = $issuesRegistryJobService->run($this->secondsFromNow(0));
        $this->fakeIssuesQueryBackend->addEvent($this->now + 30, 'group-d');
        $issuesRegistryJobService->run($this->secondsFromNow(120));

        self::assertTrue($firstRunResult->explosion);
        self::assertCount(1, $this->recordingIssuesAlertWebhookSender->sentTexts);
        self::assertStringContainsString('fingerprint explosion', $this->recordingIssuesAlertWebhookSender->sentTexts[0]);
        self::assertFalse($this->findGroup('group-d')->alertPending);
    }

    public function testStormAboveTheRawLineCapIsCountedInFull(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        $this->fakeIssuesQueryBackend->addEvent($this->now - 600, 'group-storm', occurrences: 250000);

        $issuesRegistryJobResult = $issuesRegistryJobService->run($this->secondsFromNow(0));

        self::assertSame(250000, $issuesRegistryJobResult->eventsCounted);
        self::assertSame(250000, $this->findGroup('group-storm')->eventCount);
        self::assertCount(1, $this->recordingIssuesAlertWebhookSender->sentTexts);
    }

    public function testOutageIsCaughtUpFromTheWatermark(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        $this->fakeIssuesQueryBackend->addEvent($this->now - 600, 'group-a');
        $issuesRegistryJobService->run($this->secondsFromNow(0));
        // the job is down for three hours — longer than the discovery window — while the error keeps happening
        $this->fakeIssuesQueryBackend->addEvent($this->now + 1800, 'group-a', occurrences: 40);
        $this->fakeIssuesQueryBackend->addEvent($this->now + 9000, 'group-a', occurrences: 2);

        $issuesRegistryJobService->run($this->secondsFromNow(10800));

        self::assertSame(43, $this->findGroup('group-a')->eventCount);
    }

    public function testFailedAlertStaysPendingAndIsRetried(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        $this->recordingIssuesAlertWebhookSender->failSends = true;
        $this->fakeIssuesQueryBackend->addEvent($this->now - 600, 'group-a');

        $failedRunResult = $issuesRegistryJobService->run($this->secondsFromNow(0));
        self::assertSame(1, $failedRunResult->alertsFailed);
        self::assertTrue($this->findGroup('group-a')->alertPending);

        $this->recordingIssuesAlertWebhookSender->failSends = false;
        $retryRunResult = $issuesRegistryJobService->run($this->secondsFromNow(60));
        self::assertSame(1, $retryRunResult->alertsSent);
        self::assertFalse($this->findGroup('group-a')->alertPending);
    }

    public function testWarningsAreNotRegistered(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        $this->fakeIssuesQueryBackend->addEvent($this->now - 600, 'group-warning', level: 'warning');

        $issuesRegistryJobResult = $issuesRegistryJobService->run($this->secondsFromNow(0));

        self::assertSame(0, $issuesRegistryJobResult->groupsCreated);
    }

    public function testHealthReportsAStalledJobAndASilentCollector(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        $this->fakeIssuesQueryBackend->addEvent($this->now - 600, 'group-a');
        $issuesRegistryJobService->run($this->secondsFromNow(0));
        static::entityManager()->clear();

        $freshHealth = $issuesRegistryJobService->getHealth($this->secondsFromNow(60));
        self::assertTrue($freshHealth->isHealthy());

        $staleHealth = $issuesRegistryJobService->getHealth($this->secondsFromNow(7200));
        self::assertTrue($staleHealth->jobStalled);
        self::assertTrue($staleHealth->collectorSilent);
        self::assertFalse($staleHealth->isHealthy());

        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            /** @var IssuesJobStatesService $issuesJobStatesService */
            $issuesJobStatesService = IssuesJobState::getService();
            self::assertSame($this->now - 60, $issuesJobStatesService->getState($issuesRegistryJobService->getEnvironmentNames()[0])->watermark->getTimestamp());
            /** @var IssuesService $issuesService */
            $issuesService = Issue::getService();
            self::assertNotNull($issuesService->findByIssueHash('group-a-route:test'));
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function testAnEnvironmentWithoutErrorsIsNotASilentCollector(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        // only warnings: no issue groups, but the collector ships
        $this->fakeIssuesQueryBackend->addEvent($this->now - 300, 'group-warning', level: 'warning');

        $issuesRegistryJobResult = $issuesRegistryJobService->run($this->secondsFromNow(0));
        static::entityManager()->clear();
        $issuesRegistryHealth = $issuesRegistryJobService->getHealth($this->secondsFromNow(60));

        self::assertSame(0, $issuesRegistryJobResult->groupsCreated);
        self::assertFalse($issuesRegistryHealth->collectorSilent);
        self::assertTrue($issuesRegistryHealth->isHealthy());
    }

    public function testTheJobOnlyHealthCheckIgnoresASilentCollector(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        $issuesRegistryJobService->run($this->secondsFromNow(0));
        static::entityManager()->clear();
        /** @var IssuesRegistryJobService $containerJobService */
        $containerJobService = DDDService::instance()->getService(IssuesRegistryJobService::class);
        $containerJobService->setSettings($issuesRegistryJobService->getSettings());

        try {
            // no line at all: the collector is silent, the job is not stalled
            $fullCommandTester = new CommandTester(new IssuesHealth());
            $jobOnlyCommandTester = new CommandTester(new IssuesHealth());
            self::assertSame(Command::FAILURE, $fullCommandTester->execute([]));
            self::assertStringContainsString('app:issues:test', $fullCommandTester->getDisplay());
            self::assertSame(Command::SUCCESS, $jobOnlyCommandTester->execute(['--job-only' => true]));
        } finally {
            $containerJobService->setSettings(null);
        }
    }
}
