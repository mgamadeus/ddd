<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Query\Elasticsearch;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One `key: value` pair of an occurrence's `route_parameters` or `messenger.message_keys`; the value as a string,
 * redacted.
 */
class RuntimeErrorKeyValue extends ValueObject
{
    /** The parameter or property name, e.g. `orderId` */
    public string $key = '';

    /** The value as logged, cast to a string (`true` / `false` for a bool), redacted; null when logged as null */
    public ?string $value = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->key);
    }
}
