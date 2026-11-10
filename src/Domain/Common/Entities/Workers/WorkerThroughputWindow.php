<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the workers finished in one fixed window back from now (5 minutes, 15 minutes, an hour, a day), counted from
 * the statistics' minute buckets that start in the window. The rates follow by division:
 * {@see self::getHandledPerMinute()}, {@see self::getHandledPerWorkerMinute()}.
 */
class WorkerThroughputWindow extends ValueObject
{
    /** @var int Length of the window in seconds */
    public int $windowSeconds = 0;

    /** @var int Messages finished in the window, failed ones included */
    public int $handledCount = 0;

    /** @var int Of those, messages that failed (thrown or caught) */
    public int $failedCount = 0;

    /**
     * @var int Seconds worker processes were alive in the window, all processes added up: per sampled minute the
     * highest number of live processes × 60. A minute without a sample adds nothing
     */
    public int $workerSeconds = 0;

    public function __construct(int $windowSeconds = 0)
    {
        $this->windowSeconds = $windowSeconds;
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)$this->windowSeconds);
    }

    /** @return float|null Messages finished per minute of the window; null for a window without length */
    public function getHandledPerMinute(): ?float
    {
        return $this->windowSeconds > 0 ? $this->handledCount / ($this->windowSeconds / 60) : null;
    }

    /** @return float|null Messages finished per minute of one worker process; null when no process was sampled */
    public function getHandledPerWorkerMinute(): ?float
    {
        return $this->workerSeconds > 0 ? $this->handledCount / ($this->workerSeconds / 60) : null;
    }
}
