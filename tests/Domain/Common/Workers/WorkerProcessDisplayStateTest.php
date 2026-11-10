<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Workers;

use DDD\Domain\Common\Entities\Workers\WorkerProcess;
use DDD\Infrastructure\Base\DateTime\DateTime;
use PHPUnit\Framework\TestCase;

class WorkerProcessDisplayStateTest extends TestCase
{
    protected const int NOW = 1_800_000_000;
    protected const int LOST_AFTER_SECONDS = 30;
    protected const int BUSY_SILENT_SECONDS = 3600;

    protected function createProcess(string $state, int $lastSeenSecondsAgo): WorkerProcess
    {
        $workerProcess = new WorkerProcess();
        $workerProcess->processKey = 'host-1:12:' . self::NOW;
        $workerProcess->transport = 'send_mail,import_files';
        $workerProcess->state = $state;
        $workerProcess->lastSeenAt = DateTime::fromTimestamp(self::NOW - $lastSeenSecondsAgo);
        return $workerProcess;
    }

    protected function getDisplayState(WorkerProcess $workerProcess): string
    {
        return $workerProcess->getDisplayState(DateTime::fromTimestamp(self::NOW), self::LOST_AFTER_SECONDS, self::BUSY_SILENT_SECONDS);
    }

    public function testAnIdleProcessWithARecentBeatIsRunning(): void
    {
        $workerProcess = $this->createProcess(WorkerProcess::STATE_RUNNING, 10);
        $this->assertSame(WorkerProcess::STATE_RUNNING, $this->getDisplayState($workerProcess));
        $this->assertTrue($workerProcess->isLive(DateTime::fromTimestamp(self::NOW), self::LOST_AFTER_SECONDS, self::BUSY_SILENT_SECONDS));
    }

    public function testAnIdleProcessWithoutABeatIsLost(): void
    {
        $workerProcess = $this->createProcess(WorkerProcess::STATE_RUNNING, 31);
        $this->assertSame(WorkerProcess::DISPLAY_STATE_LOST, $this->getDisplayState($workerProcess));
        $this->assertFalse($workerProcess->isLive(DateTime::fromTimestamp(self::NOW), self::LOST_AFTER_SECONDS, self::BUSY_SILENT_SECONDS));
    }

    public function testAProcessInALongJobIsBusyNotLost(): void
    {
        $workerProcess = $this->createProcess(WorkerProcess::STATE_RUNNING, 600);
        $workerProcess->currentMessageClass = 'App\\ImportMessage';
        $workerProcess->currentMessageSince = DateTime::fromTimestamp(self::NOW - 600);
        $this->assertSame(WorkerProcess::DISPLAY_STATE_BUSY, $this->getDisplayState($workerProcess));
        $this->assertTrue($workerProcess->isLive(DateTime::fromTimestamp(self::NOW), self::LOST_AFTER_SECONDS, self::BUSY_SILENT_SECONDS));
    }

    public function testAJobBeyondTheSilentThresholdIsSilent(): void
    {
        $workerProcess = $this->createProcess(WorkerProcess::STATE_RUNNING, 4000);
        $workerProcess->currentMessageClass = 'App\\ImportMessage';
        $workerProcess->currentMessageSince = DateTime::fromTimestamp(self::NOW - 3601);
        $this->assertSame(WorkerProcess::DISPLAY_STATE_SILENT, $this->getDisplayState($workerProcess));
    }

    public function testAStoppedProcessIsStoppedAndALostOneLost(): void
    {
        $workerProcess = $this->createProcess(WorkerProcess::STATE_STOPPED, 5);
        $workerProcess->stopReason = WorkerProcess::STOP_REASON_MESSAGE_LIMIT;
        $this->assertSame(WorkerProcess::STATE_STOPPED, $this->getDisplayState($workerProcess));
        $workerProcess->stopReason = WorkerProcess::STOP_REASON_LOST;
        $this->assertSame(WorkerProcess::DISPLAY_STATE_LOST, $this->getDisplayState($workerProcess));
    }

    public function testTransportNames(): void
    {
        $workerProcess = $this->createProcess(WorkerProcess::STATE_RUNNING, 1);
        $this->assertSame(['send_mail', 'import_files'], $workerProcess->getTransportNames());
        $this->assertTrue($workerProcess->consumesTransport('import_files'));
        $this->assertFalse($workerProcess->consumesTransport('import'));
    }
}
