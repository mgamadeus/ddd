<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property WorkerThroughputWindow[] $elements
 * @method WorkerThroughputWindow[] getElements()
 * @method WorkerThroughputWindow|null getByUniqueKey(string $uniqueKey)
 */
class WorkerThroughputWindows extends ObjectSet
{
    /** The windows of the overview in seconds: 5 minutes, 15 minutes, an hour, a day */
    public const array WINDOW_SECONDS = [300, 900, 3600, 86400];

    /**
     * @param int[] $windowSeconds
     * @return static One empty window per length, in the given order
     */
    public static function fromWindowSeconds(array $windowSeconds = self::WINDOW_SECONDS): static
    {
        $workerThroughputWindows = new static();
        foreach ($windowSeconds as $seconds) {
            $workerThroughputWindow = new WorkerThroughputWindow($seconds);
            $workerThroughputWindows->add($workerThroughputWindow);
        }
        return $workerThroughputWindows;
    }

    public function getByWindowSeconds(int $windowSeconds): ?WorkerThroughputWindow
    {
        return $this->getByUniqueKey(WorkerThroughputWindow::uniqueKeyStatic((string)$windowSeconds));
    }

    /** Adds the counts of another set to the windows of the same length */
    public function addCountsOf(WorkerThroughputWindows $workerThroughputWindows): void
    {
        foreach ($workerThroughputWindows->getElements() as $otherWindow) {
            $workerThroughputWindow = $this->getByWindowSeconds($otherWindow->windowSeconds);
            if ($workerThroughputWindow === null) {
                continue;
            }
            $workerThroughputWindow->handledCount += $otherWindow->handledCount;
            $workerThroughputWindow->failedCount += $otherWindow->failedCount;
            $workerThroughputWindow->workerSeconds += $otherWindow->workerSeconds;
        }
    }
}
