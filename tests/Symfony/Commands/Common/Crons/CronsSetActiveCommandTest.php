<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Commands\Common\Crons;

use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Domain\Common\Entities\Crons\Crons;
use DDD\Domain\Common\Services\CronsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Common\Crons\CronsActivate;
use DDD\Symfony\Commands\Common\Crons\CronsDeactivate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Finds Crons in memory instead of the database; every test runs the commands with --dryRun, so nothing is written. */
class InMemoryCronsService extends CronsService
{
    /** @var array<string, Cron> Method-local lookup for the test, keyed by unique Cron name */
    public array $cronsByName = [];

    public function findByName(string $name): ?Cron
    {
        return $this->cronsByName[$name] ?? null;
    }
}

/** The CLI admin lives in the database; the switch logic under test does not need it. */
class ActivateWithoutAdminLookup extends CronsActivate
{
    protected function setDefaultAdminAccountAsAuthContext(): void
    {
    }
}

class DeactivateWithoutAdminLookup extends CronsDeactivate
{
    protected function setDefaultAdminAccountAsAuthContext(): void
    {
    }
}

class CronsSetActiveCommandTest extends TestCase
{
    protected InMemoryCronsService $cronsService;

    protected function setUp(): void
    {
        $this->cronsService = new InMemoryCronsService();
        $cronsClassName = DDDService::instance()->getContainerServiceClassNameForClass(Crons::class);
        StaticRegistry::$entityServices[$cronsClassName] = $this->cronsService;
    }

    protected function tearDown(): void
    {
        $cronsClassName = DDDService::instance()->getContainerServiceClassNameForClass(Crons::class);
        unset(StaticRegistry::$entityServices[$cronsClassName]);
    }

    protected function registerCron(string $name, bool $isActive, int $slotTimestamp): Cron
    {
        $cron = new Cron();
        $cron->name = $name;
        $cron->schedule = '0 3 * * *';
        $cron->isActive = $isActive;
        $cron->nextExecutionScheduledAt = DateTime::fromTimestamp($slotTimestamp);
        $this->cronsService->cronsByName[$name] = $cron;
        return $cron;
    }

    public function testActivateReanchorsAPausedCronAtItsNextSlotAndWritesNothingOnADryRun(): void
    {
        $cron = $this->registerCron('reap stuck conversations', false, time() - 7 * 86400);
        $commandTester = new CommandTester(new ActivateWithoutAdminLookup('app:crons:activate'));

        $exitCode = $commandTester->execute(['names' => ['reap stuck conversations'], '--dryRun' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('DRY RUN — would activate', $commandTester->getDisplay());
        $this->assertGreaterThan(time(), $cron->nextExecutionScheduledAt->getTimestamp());
    }

    /** One name not found fails the whole call, but every other name is still reported. */
    public function testANameThatIsNotFoundFailsTheCallAndTheOthersAreStillReported(): void
    {
        $this->registerCron('already on', true, time() + 3600);
        $commandTester = new CommandTester(new ActivateWithoutAdminLookup('app:crons:activate'));

        $exitCode = $commandTester->execute(['names' => ['already on', 'does not exist'], '--dryRun' => true]);

        $display = $commandTester->getDisplay();
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('unchanged (already active)', $display);
        $this->assertStringContainsString('NOT FOUND', $display);
        $this->assertStringContainsString('1 of 2 Cron(s) could not be activated', $display);
    }

    public function testDeactivateSwitchesSeveralCronsOffInOneCall(): void
    {
        $first = $this->registerCron('first', true, time() + 3600);
        $second = $this->registerCron('second', true, time() + 7200);
        $commandTester = new CommandTester(new DeactivateWithoutAdminLookup('app:crons:deactivate'));

        $exitCode = $commandTester->execute(['names' => ['first', 'second'], '--dryRun' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFalse($first->isActive);
        $this->assertFalse($second->isActive);
        $this->assertStringContainsString('2 Cron(s) deactivated (nothing written)', $commandTester->getDisplay());
    }
}
