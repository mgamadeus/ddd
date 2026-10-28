<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Issues;

use DDD\Domain\Common\Entities\Issues\IssueGroup;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Common\Issues\IssuesHealth;
use DDD\Symfony\Commands\Common\Issues\IssuesList;
use DDD\Symfony\Commands\Common\Issues\IssuesResolve;
use DDD\Symfony\Commands\Common\Issues\IssuesShow;
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
}
