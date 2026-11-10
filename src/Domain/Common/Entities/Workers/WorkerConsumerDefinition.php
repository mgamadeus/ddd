<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One consumer of the worker list: the workers of one Messenger transport. The framework reads the entry; it never
 * starts or scales anything — the counts say what the infrastructure is asked to run.
 */
class WorkerConsumerDefinition extends ValueObject
{
    /** Keys of a consumer entry the framework reads; every other key goes to {@see self::$additionalSettings} */
    public const array KNOWN_KEYS = [
        'transport', 'replicas', 'min', 'max', 'scaleDownAfter', 'limit', 'memoryLimit', 'devOnly', 'messageLog',
        'busySilentSeconds',
    ];

    /** @var string The Messenger transport */
    public string $transport = '';

    /** @var int|null Fixed number of worker processes (used where the consumer is not autoscaled) */
    public ?int $replicas = null;

    /** @var int|null Autoscaled: lowest number of processes (0 when `max` is set and `min` is not) */
    public ?int $min = null;

    /** @var int|null Autoscaled: highest number of processes; set = the consumer is autoscaled */
    public ?int $max = null;

    /** @var int|null Autoscaled: idle seconds before processes are taken away */
    public ?int $scaleDownAfter = null;

    /** @var int|null Messages a process handles before it restarts (`--limit`) */
    public ?int $limit = null;

    /** @var string|null PHP memory limit of a process (`--memory-limit`, e.g. 256M) */
    public ?string $memoryLimit = null;

    /** @var bool Only on development stages */
    public bool $devOnly = false;

    /** @var string|null all, failures or off ({@see WorkersSettings}); null = the application's default */
    public ?string $messageLog = null;

    /** @var int|null Seconds a message of this transport may run before its process is shown as silent; null = the `busySilentSeconds` setting */
    public ?int $busySilentSeconds = null;

    /** @var WorkerDefinitionSettings Keys of the entry the framework does not interpret */
    public WorkerDefinitionSettings $additionalSettings;

    public function __construct()
    {
        parent::__construct();
        $this->additionalSettings = new WorkerDefinitionSettings();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->transport);
    }

    public function isAutoscaled(): bool
    {
        return $this->max !== null;
    }

    /** @return int Lowest number of processes to expect: `min` (default 0) when autoscaled, else `replicas` (default 1) */
    public function getEffectiveMin(): int
    {
        return $this->isAutoscaled() ? ($this->min ?? 0) : ($this->replicas ?? 1);
    }

    /** @return int Highest number of processes to expect: `max` when autoscaled, else `replicas` (default 1) */
    public function getEffectiveMax(): int
    {
        return $this->isAutoscaled() ? (int)$this->max : ($this->replicas ?? 1);
    }
}
