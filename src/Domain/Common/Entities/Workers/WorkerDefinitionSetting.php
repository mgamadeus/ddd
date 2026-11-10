<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A key of a worker list entry the framework does not interpret (container memory, scratch directory, …), with its
 * value as a string. Kept so tooling that reads the definitions through the framework sees the whole entry.
 */
class WorkerDefinitionSetting extends ValueObject
{
    public function __construct(public string $key = '', public string $value = '')
    {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->key);
    }
}
