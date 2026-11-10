<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The application's worker list as read from its definition file: the consumers (one per Messenger transport) and the
 * loops.
 */
class WorkerDefinitions extends ValueObject
{
    /** @var string Absolute path of the definition file */
    public string $definitionFile = '';

    /** @var bool false = the file does not exist: no definitions */
    public bool $definitionFileExists = false;

    public WorkerConsumerDefinitions $consumers;

    public WorkerLoopDefinitions $loops;

    /** @var string[] Transports that have more than one consumer entry (the first one counts) */
    public array $duplicateTransports = [];

    public function __construct()
    {
        parent::__construct();
        $this->consumers = new WorkerConsumerDefinitions();
        $this->loops = new WorkerLoopDefinitions();
    }
}
