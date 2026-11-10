<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The throughput windows of one transport.
 */
class WorkerTransportThroughput extends ValueObject
{
    public string $transport = '';

    public WorkerThroughputWindows $throughputWindows;

    /**
     * @param int[] $windowSeconds
     */
    public function __construct(string $transport = '', array $windowSeconds = WorkerThroughputWindows::WINDOW_SECONDS)
    {
        $this->transport = $transport;
        $this->throughputWindows = WorkerThroughputWindows::fromWindowSeconds($windowSeconds);
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->transport);
    }
}
