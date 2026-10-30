<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The key-value pairs of an occurrence's `route_parameters` or `messenger.message_keys`, in logged order.
 *
 * @property RuntimeErrorKeyValue[] $elements
 * @method RuntimeErrorKeyValue|null first()
 * @method RuntimeErrorKeyValue|null getByUniqueKey(string $uniqueKey)
 * @method RuntimeErrorKeyValue[] getElements()
 */
class RuntimeErrorKeyValues extends ObjectSet
{
    /**
     * The pairs of a logged map; arrays and objects in a value become JSON, every value passes the redactor.
     *
     * @param array<string|int, mixed> $loggedMap
     */
    public static function buildFromLoggedMap(array $loggedMap, RuntimeErrorFieldRedactor $fieldRedactor): static
    {
        $runtimeErrorKeyValues = new static();
        foreach ($loggedMap as $key => $value) {
            $runtimeErrorKeyValue = new RuntimeErrorKeyValue();
            $runtimeErrorKeyValue->key = (string)$key;
            $runtimeErrorKeyValue->value = match (true) {
                $value === null => null,
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => $fieldRedactor->redact((string)$value),
                default => $fieldRedactor->redact((string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            };
            $runtimeErrorKeyValues->add($runtimeErrorKeyValue);
        }
        return $runtimeErrorKeyValues;
    }
}
