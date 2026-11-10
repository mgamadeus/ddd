<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One loop of the worker list: a console command the infrastructure runs again and again (a cron runner, …). Listed
 * as a definition; loops are not measured.
 */
class WorkerLoopDefinition extends ValueObject
{
    /** Keys of a loop entry the framework reads; every other key goes to {@see self::$additionalSettings} */
    public const array KNOWN_KEYS = ['name', 'args', 'sleepSeconds', 'prodOnly'];

    public string $name = '';

    /** @var string The console arguments, as one command line */
    public string $args = '';

    /** @var int|null Pause between two runs; 0 = the command loops itself */
    public ?int $sleepSeconds = null;

    /** @var bool Only on production */
    public bool $prodOnly = false;

    /** @var WorkerDefinitionSettings Keys of the entry the framework does not interpret */
    public WorkerDefinitionSettings $additionalSettings;

    public function __construct()
    {
        parent::__construct();
        $this->additionalSettings = new WorkerDefinitionSettings();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->name);
    }
}
