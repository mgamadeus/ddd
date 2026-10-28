<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Issues;

use DDD\Domain\Common\Entities\Issues\Issue;
use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Domain\Common\Entities\Issues\IssuesJobState;
use DDD\Domain\Common\Services\IssueGroupsService;
use DDD\Domain\Common\Services\IssuesJobStatesService;
use DDD\Domain\Common\Services\IssuesRegistryJobService;
use DDD\Domain\Common\Services\IssuesTrendService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Logging\IssuesQueryEnvironments;
use DDD\Infrastructure\Logging\IssuesRegistrySettings;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Common\Issues\IssuesHealth;
use DDD\Symfony\Commands\Common\Issues\IssuesList;
use DDD\Symfony\Commands\Common\Issues\IssuesResolve;
use DDD\Symfony\Commands\Common\Issues\IssuesSearch;
use DDD\Symfony\Commands\Common\Issues\IssuesShow;
use DDD\Symfony\Commands\Common\Issues\IssuesTrend;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Several environments in one registry (prod, testing, a dev workspace): the key environment + hash, state, seed mode
 * and alerts per environment, a failing environment does not stop the others, the adoption of rows from before
 * environments, the status counts, and the commands' `--environment` / `--workspace`.
 */
class IssuesRegistryEnvironmentsTest extends IssuesRegistryTestCase
{
    /** @var array<string, FakeIssuesQueryBackend> Test fixture: environment → its backend */
    protected array $fakeIssuesQueryBackends = [];

    protected RecordingIssuesAlertWebhookSender $recordingIssuesAlertWebhookSender;

    protected int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = time();
        $this->fakeIssuesQueryBackends = ['prod' => new FakeIssuesQueryBackend(), 'testing' => new FakeIssuesQueryBackend(), 'mgn' => new FakeIssuesQueryBackend()];
    }

    protected function tearDown(): void
    {
        DDDService::instance()->getService(IssuesRegistryJobService::class)->setSettings(null);
        parent::tearDown();
    }

    protected function createJobService(?string $environmentsJson = null, int $seedHours = 0): IssuesRegistryJobService
    {
        $issuesRegistrySettings = new IssuesRegistrySettings(
            queryBackend: IssuesRegistrySettings::BACKEND_LOKI,
            querySelector: '{job="single"}',
            alertWebhook: 'http://webhook.invalid/hook',
            alertLinkTemplate: 'https://admin.example/issues/{environment}/{group_hash}',
            seedHours: $seedHours,
            queryEnvironments: IssuesQueryEnvironments::fromJson($environmentsJson
                ?? '{"prod": "{workspace=\"prod\"}", "testing": "{workspace=\"testing\"}", "mgn": "{workspace=\"mgn\"}"}'),
            defaultEnvironment: 'prod',
        );
        $this->recordingIssuesAlertWebhookSender = new RecordingIssuesAlertWebhookSender($issuesRegistrySettings);
        // the commands resolve the job service from the container: the same instance
        /** @var IssuesRegistryJobService $issuesRegistryJobService */
        $issuesRegistryJobService = DDDService::instance()->getService(IssuesRegistryJobService::class);
        $issuesRegistryJobService->setSettings($issuesRegistrySettings);
        $issuesRegistryJobService->setAlertWebhookSender($this->recordingIssuesAlertWebhookSender);
        foreach ($issuesRegistrySettings->getQueryEnvironments()->getNames() as $environment) {
            $issuesRegistryJobService->setQueryBackendForEnvironment($environment, $this->fakeIssuesQueryBackends[$environment]);
        }
        return $issuesRegistryJobService;
    }

    protected function getIssueGroupsService(): IssueGroupsService
    {
        static::entityManager()->clear();
        /** @var IssueGroupsService $issueGroupsService */
        $issueGroupsService = IssueGroup::getService();
        return $issueGroupsService;
    }

    /** Runs the callable without rights restrictions */
    protected function withoutRights(callable $callable): mixed
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            return $callable();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    public function testOneHashIsOneGroupPerEnvironment(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        $this->fakeIssuesQueryBackends['prod']->addEvent($this->now - 600, 'group-a', workspace: 'prod', occurrences: 3);
        $this->fakeIssuesQueryBackends['testing']->addEvent($this->now - 600, 'group-a', workspace: 'testing');
        $this->fakeIssuesQueryBackends['mgn']->addEvent($this->now - 600, 'group-b', workspace: 'mgn');

        $issuesRegistryJobResult = $issuesRegistryJobService->run(DateTime::fromTimestamp($this->now));

        self::assertSame(['prod', 'testing', 'mgn'], $issuesRegistryJobResult->environments);
        self::assertSame(3, $issuesRegistryJobResult->groupsCreated);
        self::assertSame(3, $issuesRegistryJobResult->issuesCreated);
        self::assertCount(3, $this->recordingIssuesAlertWebhookSender->sentTexts);
        self::assertStringContainsString(':new: New error [testing]', implode("\n", $this->recordingIssuesAlertWebhookSender->sentTexts));
        self::assertStringContainsString('https://admin.example/issues/prod/group-a', implode("\n", $this->recordingIssuesAlertWebhookSender->sentTexts));
        $this->withoutRights(function (): void {
            $issueGroupsService = $this->getIssueGroupsService();
            $prodGroup = $issueGroupsService->findByGroupHash('group-a', 'prod');
            self::assertSame(3, $prodGroup->eventCount);
            self::assertSame('prod', $prodGroup->workspace);
            self::assertSame(1, $issueGroupsService->findByGroupHash('group-a', 'testing')->eventCount);
            self::assertNull($issueGroupsService->findByGroupHash('group-b', 'prod'));
            self::assertSame(2, $issueGroupsService->findGroupsByGroupHash('group-a')->count());

            // status per environment: resolving prod leaves testing open
            $issueGroupsService->resolve($prodGroup);
            $issueGroupStatusCounts = $this->getIssueGroupsService()->countGroupsByEnvironmentAndStatus();
            self::assertSame(1, $issueGroupStatusCounts->getCount('prod', IssueGroup::STATUS_RESOLVED));
            self::assertSame(0, $issueGroupStatusCounts->getCount('prod', IssueGroup::STATUS_OPEN));
            self::assertSame(1, $issueGroupStatusCounts->getCount('testing', IssueGroup::STATUS_OPEN));
            self::assertSame(2, $issueGroupStatusCounts->getCount(null, IssueGroup::STATUS_OPEN));
            self::assertSame(['mgn', 'prod', 'testing'], $issueGroupStatusCounts->getEnvironments());
            /** @var IssuesJobStatesService $issuesJobStatesService */
            $issuesJobStatesService = IssuesJobState::getService();
            foreach (['prod', 'testing', 'mgn'] as $environment) {
                self::assertNotNull($issuesJobStatesService->getState($environment)->lastSuccessfulRunAt, $environment);
            }
        });
    }

    public function testSeedModeAndFailuresArePerEnvironment(): void
    {
        $issuesRegistryJobService = $this->createJobService('{"prod": "{workspace=\"prod\"}", "testing": "{workspace=\"testing\"}"}', seedHours: 24);
        $this->fakeIssuesQueryBackends['prod']->addEvent($this->now - 600, 'group-a');
        $issuesRegistryJobService->run(DateTime::fromTimestamp($this->now));
        // a day later: prod is out of seed mode; mgn joins now and starts its own seed period; testing is down
        $issuesRegistryJobService = $this->createJobService(seedHours: 24);
        $issuesRegistryJobService->setQueryBackendForEnvironment('testing', new FailingIssuesQueryBackend());
        $later = $this->now + 25 * 3600;
        $this->fakeIssuesQueryBackends['prod']->addEvent($later - 300, 'group-new-in-prod');
        $this->fakeIssuesQueryBackends['mgn']->addEvent($later - 300, 'group-new-in-mgn');

        try {
            $issuesRegistryJobService->run(DateTime::fromTimestamp($later));
            self::fail('the failing environment is reported');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame('backend down', $runtimeException->getMessage());
        }

        self::assertCount(1, $this->recordingIssuesAlertWebhookSender->sentTexts);
        self::assertStringContainsString('[prod]', $this->recordingIssuesAlertWebhookSender->sentTexts[0]);
        $this->withoutRights(function (): void {
            self::assertNotNull($this->getIssueGroupsService()->findByGroupHash('group-new-in-mgn', 'mgn'));
            /** @var IssuesJobStatesService $issuesJobStatesService */
            $issuesJobStatesService = IssuesJobState::getService();
            self::assertSame('backend down', $issuesJobStatesService->getState('testing')->lastError);
            self::assertNull($issuesJobStatesService->getState('prod')->lastError);
        });
        $issuesRegistryHealth = $issuesRegistryJobService->getHealth(DateTime::fromTimestamp($later + 60));
        self::assertSame(3, $issuesRegistryHealth->environments->count());
        self::assertTrue($issuesRegistryHealth->jobStalled);
        self::assertStringStartsWith('testing: backend down', (string)$issuesRegistryHealth->lastError);
    }

    public function testOneEnvironmentAdoptsTheRowsFromBeforeEnvironments(): void
    {
        // rows of 3.3: no environment, the job state "default"
        $this->withoutRights(function (): void {
            $issueGroup = new IssueGroup();
            $issueGroup->groupHash = 'group-old';
            $issueGroup->registeredAt = DateTime::fromTimestamp($this->now - 7200);
            $issueGroup->firstSeenAt = $issueGroup->registeredAt;
            $issueGroup->lastSeenAt = $issueGroup->registeredAt;
            $issueGroup = $issueGroup->insert(0);
            $issue = new Issue();
            $issue->issueHash = 'group-old-route:test';
            $issue->issueGroupId = $issueGroup->id;
            $issue->insert(0);
            $issuesJobState = new IssuesJobState();
            $issuesJobState->name = IssuesJobState::DEFAULT_NAME;
            $issuesJobState->activatedAt = DateTime::fromTimestamp($this->now - 30 * 86400);
            $issuesJobState->insert(0);
        });
        $issuesRegistryJobService = $this->createJobService('{"prod": "{workspace=\"prod\"}"}', seedHours: 24);
        $this->fakeIssuesQueryBackends['prod']->addEvent($this->now - 600, 'group-old', issueHash: 'group-old-route:test');

        $issuesRegistryJobResult = $issuesRegistryJobService->run(DateTime::fromTimestamp($this->now));

        // not new (adopted), no new seed period (the old state's activation counts)
        self::assertSame(0, $issuesRegistryJobResult->groupsCreated);
        self::assertSame(0, $issuesRegistryJobResult->issuesCreated);
        self::assertFalse($issuesRegistryJobResult->seedMode);
        $this->withoutRights(function (): void {
            self::assertSame(1, $this->getIssueGroupsService()->findByGroupHash('group-old', 'prod')->eventCount);
            /** @var IssuesJobStatesService $issuesJobStatesService */
            $issuesJobStatesService = IssuesJobState::getService();
            self::assertNull($issuesJobStatesService->getState(IssuesJobState::DEFAULT_NAME)->id);
        });
    }

    public function testCommandsWithEnvironmentAndWorkspace(): void
    {
        $issuesRegistryJobService = $this->createJobService();
        $this->fakeIssuesQueryBackends['prod']->addEvent($this->now - 600, 'group-a', workspace: 'prod');
        $this->fakeIssuesQueryBackends['testing']->addEvent($this->now - 500, 'group-a', workspace: 'testing');
        $this->fakeIssuesQueryBackends['mgn']->addEvent($this->now - 400, 'group-b', workspace: 'mgn');
        $issuesRegistryJobService->run(DateTime::fromTimestamp($this->now));

        $listCommandTester = new CommandTester(new IssuesList());
        self::assertSame(Command::SUCCESS, $listCommandTester->execute(['--environment' => 'mgn', '--format' => 'json']));
        $listPayload = json_decode($listCommandTester->getDisplay(), true);
        self::assertSame(1, $listPayload['count']);
        self::assertSame('mgn', $listPayload['groups'][0]['environment']);
        self::assertSame('mgn', $listPayload['groups'][0]['workspace']);
        self::assertSame('https://admin.example/issues/mgn/group-b', $listPayload['groups'][0]['link']);
        self::assertContains(['environment' => 'testing', 'status' => 'OPEN', 'count' => 1], $listPayload['statusCounts']);

        // a hash in two environments needs --environment
        self::assertSame(Command::INVALID, (new CommandTester(new IssuesShow()))->execute(['groupHash' => 'group-a']));
        $showCommandTester = new CommandTester(new IssuesShow());
        self::assertSame(Command::SUCCESS, $showCommandTester->execute(['groupHash' => 'group-a', '--environment' => 'testing', '--format' => 'json']));
        $showPayload = json_decode($showCommandTester->getDisplay(), true);
        self::assertSame('testing', $showPayload['environment']);
        self::assertSame('testing', $showPayload['workspace']);
        self::assertCount(1, $showPayload['events']);

        $resolveCommandTester = new CommandTester(new IssuesResolve());
        self::assertSame(Command::SUCCESS, $resolveCommandTester->execute(['groupHash' => 'group-a', '--environment' => 'prod', '--format' => 'json']));
        self::assertSame(['groupHash' => 'group-a', 'environment' => 'prod', 'workspace' => 'prod', 'status' => 'RESOLVED'], json_decode($resolveCommandTester->getDisplay(), true));

        $searchCommandTester = new CommandTester(new IssuesSearch());
        self::assertSame(Command::SUCCESS, $searchCommandTester->execute(['--since' => '1h', '--format' => 'json']));
        $searchPayload = json_decode($searchCommandTester->getDisplay(), true);
        self::assertSame(['prod', 'testing', 'mgn'], $searchPayload['environments']);
        self::assertSame(3, $searchPayload['count']);
        self::assertSame('mgn', $searchPayload['events'][0]['issues.environment']);
        $workspaceCommandTester = new CommandTester(new IssuesSearch());
        self::assertSame(Command::SUCCESS, $workspaceCommandTester->execute(['--since' => '1h', '--workspace' => 'testing', '--format' => 'json']));
        $workspacePayload = json_decode($workspaceCommandTester->getDisplay(), true);
        self::assertSame(1, $workspacePayload['count']);
        self::assertSame('testing', $workspacePayload['events'][0]['issues.workspace']);

        $healthCommandTester = new CommandTester(new IssuesHealth());
        $healthCommandTester->execute(['--format' => 'json']);
        self::assertStringContainsString('"environment": "mgn"', $healthCommandTester->getDisplay());
    }

    public function testAGroupSampledFromAnOldLineTakesTheWorkspaceFromANewerOne(): void
    {
        $issuesRegistryJobService = $this->createJobService('{"mgn": "{workspace=\\"mgn\\"}"}');
        // a line from before service.workspace, then a new one
        $this->fakeIssuesQueryBackends['mgn']->addEvent($this->now - 900, 'group-a');
        $this->fakeIssuesQueryBackends['mgn']->addEvent($this->now - 300, 'group-a', workspace: 'mgn');

        $issuesRegistryJobService->run(DateTime::fromTimestamp($this->now));

        $this->withoutRights(function (): void {
            self::assertSame('mgn', $this->getIssueGroupsService()->findByGroupHash('group-a', 'mgn')->workspace);
        });
    }

    public function testTrendPerEnvironmentSummedAndPerGroup(): void
    {
        $this->createJobService();
        $hourStart = intdiv($this->now, 3600) * 3600 - 3600;
        $this->fakeIssuesQueryBackends['prod']->addEvent($hourStart + 60, 'group-a', occurrences: 4);
        $this->fakeIssuesQueryBackends['prod']->addEvent($hourStart + 700, 'group-b', occurrences: 1);
        $this->fakeIssuesQueryBackends['prod']->addEvent($hourStart + 720, 'group-a', level: 'warning');
        $this->fakeIssuesQueryBackends['testing']->addEvent($hourStart + 90, 'group-a', occurrences: 2);
        /** @var IssuesTrendService $issuesTrendService */
        $issuesTrendService = DDDService::instance()->getService(IssuesTrendService::class);
        $issuesTrendService->setCacheEnabled(false);
        $from = DateTime::fromTimestamp($hourStart);
        $to = DateTime::fromTimestamp($hourStart + 1199);

        $prodTrend = $issuesTrendService->getTrend('prod', null, $from, $to, 600);
        $allTrend = $issuesTrendService->getTrend(null, null, $from, $to, 600);
        $groupTrend = $issuesTrendService->getTrend('prod', 'group-a', $from, $to, 600);

        self::assertSame([$hourStart => 4, $hourStart + 600 => 1], $prodTrend->getCountsByBucketStart());
        self::assertSame([$hourStart => 6, $hourStart + 600 => 1], $allTrend->getCountsByBucketStart());
        self::assertSame([$hourStart => 4, $hourStart + 600 => 0], $groupTrend->getCountsByBucketStart());
        self::assertSame('group-a', $groupTrend->groupHash);

        $trendCommandTester = new CommandTester(new IssuesTrend());
        self::assertSame(Command::SUCCESS, $trendCommandTester->execute(['--environment' => 'prod', '--since' => '2h', '--step' => '1h', '--format' => 'json']));
        $payload = json_decode($trendCommandTester->getDisplay(), true);
        self::assertSame(['environment', 'groupHash', 'from', 'to', 'stepSeconds', 'total', 'buckets'], array_keys($payload));
        self::assertSame(3600, $payload['stepSeconds']);
        self::assertSame(5, $payload['total']);
        self::assertSame(['start', 'count'], array_keys($payload['buckets'][0]));
        self::assertSame(Command::INVALID, (new CommandTester(new IssuesTrend()))->execute(['--step' => 'often']));
        $issuesTrendService->setCacheEnabled(true);
    }
}
