<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Workers\Fixtures;

use DDD\Domain\Common\Services\WorkerQueuesService;
use DDD\Domain\Common\Services\WorkerTransportsService;
use Psr\Container\ContainerInterface;

/** The queue counting over transports and DSNs a test built itself */
class FixtureDsnWorkerQueuesService extends WorkerQueuesService
{
    /**
     * @param string[] $transportDsnsByName
     */
    public function __construct(ContainerInterface $receiverLocator, protected array $transportDsnsByName)
    {
        parent::__construct($receiverLocator);
    }

    protected function getWorkerTransportsService(): WorkerTransportsService
    {
        return new WorkerTransportsService(array_keys($this->transportDsnsByName), [], $this->transportDsnsByName, []);
    }
}
