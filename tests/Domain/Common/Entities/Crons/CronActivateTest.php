<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\Crons;

use DDD\Domain\Common\Entities\Crons\Cron;
use DDD\Infrastructure\Base\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

/**
 * The ticker runs every ACTIVE Cron whose `nextExecutionScheduledAt` has passed. A Cron paused across its slot must
 * therefore be re-anchored when it is switched back on — otherwise it fires at the very next tick.
 */
class CronActivateTest extends TestCase
{
    protected function pausedCron(DateTime $storedSlot): Cron
    {
        $cron = new Cron();
        $cron->name = 'paused probe';
        $cron->schedule = '0 3 * * *';
        $cron->isActive = false;
        $cron->nextExecutionScheduledAt = $storedSlot;
        return $cron;
    }

    public function testActivatingACronPausedAcrossItsSlotMovesItToTheNextSlot(): void
    {
        $staleSlot = DateTime::fromTimestamp(time() - 14 * 86400);
        $cron = $this->pausedCron($staleSlot);

        $cron->activate();

        $this->assertTrue($cron->isActive);
        $this->assertGreaterThan(time(), $cron->nextExecutionScheduledAt->getTimestamp(), 'the stale slot would fire at the next tick');
        $this->assertSame('03:00', $cron->nextExecutionScheduledAt->format('H:i'), 'the slot comes from the schedule');
    }

    /** Same expression, so a slot that still lies ahead IS the next slot. */
    public function testActivatingKeepsAStoredSlotThatStillLiesAhead(): void
    {
        $futureSlot = DateTime::fromTimestamp(time() + 3600);
        $cron = $this->pausedCron($futureSlot);

        $cron->activate();

        $this->assertSame($futureSlot->getTimestamp(), $cron->nextExecutionScheduledAt->getTimestamp());
    }

    /** An already-active Cron is not touched: re-anchoring belongs to the off → on transition only. */
    public function testActivatingAnActiveCronChangesNothing(): void
    {
        $pastSlot = DateTime::fromTimestamp(time() - 60);
        $cron = $this->pausedCron($pastSlot);
        $cron->isActive = true;

        $cron->activate();

        $this->assertSame($pastSlot->getTimestamp(), $cron->nextExecutionScheduledAt->getTimestamp());
    }

    public function testDeactivatingLeavesTheScheduleAndTheSlotAlone(): void
    {
        $slot = DateTime::fromTimestamp(time() + 3600);
        $cron = $this->pausedCron($slot);
        $cron->isActive = true;

        $cron->deactivate();

        $this->assertFalse($cron->isActive);
        $this->assertSame('0 3 * * *', $cron->schedule);
        $this->assertSame($slot->getTimestamp(), $cron->nextExecutionScheduledAt->getTimestamp());
    }
}
