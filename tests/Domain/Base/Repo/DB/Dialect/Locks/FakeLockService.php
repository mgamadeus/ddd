<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect\Locks;

use DDD\Domain\Base\Repo\DB\Dialect\Locks\AbstractLockService;

/**
 * In-memory {@see AbstractLockService} subclass for {@see AbstractLockServiceTest} — no connection, no database.
 * Shares a single static registry of "held" names across instances so a test can simulate a second process
 * contending for the same name without a real connection.
 */
class FakeLockService extends AbstractLockService
{
    /** @var array<string, bool> name => currently held by SOME FakeLockService instance (simulates server state) */
    public static array $serverHeld = [];

    public int $acquireOnceCalls = 0;
    public int $releaseOnceCalls = 0;
    public int $closeIfIdleCalls = 0;

    /** When true, the next acquireOnce() call fails once then clears itself — simulates a timeout. */
    public bool $failNextAcquire = false;

    public static function resetServerState(): void
    {
        self::$serverHeld = [];
    }

    protected function acquireOnce(string $name, int $timeoutSeconds): bool
    {
        $this->acquireOnceCalls++;
        if ($this->failNextAcquire) {
            $this->failNextAcquire = false;
            return false;
        }
        if (self::$serverHeld[$name] ?? false) {
            return false;
        }
        self::$serverHeld[$name] = true;
        return true;
    }

    protected function releaseOnce(string $name): bool
    {
        $this->releaseOnceCalls++;
        if (!(self::$serverHeld[$name] ?? false)) {
            return false;
        }
        unset(self::$serverHeld[$name]);
        return true;
    }

    protected function closeIfIdle(): void
    {
        $this->closeIfIdleCalls++;
    }

    /** @return array<string, int> the depth map, for test assertions */
    public function depths(): array
    {
        return $this->depths;
    }
}
