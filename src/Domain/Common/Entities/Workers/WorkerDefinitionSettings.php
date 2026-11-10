<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property WorkerDefinitionSetting[] $elements
 * @method WorkerDefinitionSetting[] getElements()
 * @method WorkerDefinitionSetting|null getByUniqueKey(string $uniqueKey)
 */
class WorkerDefinitionSettings extends ObjectSet
{
    /** @return string|null The value of the key; null when the entry does not carry it */
    public function getValue(string $key): ?string
    {
        return $this->getByUniqueKey(WorkerDefinitionSetting::uniqueKeyStatic($key))?->value;
    }
}
