<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Client;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A free key/value label of a client error (e.g. `feature: checkout`).
 */
class ClientIssueTag extends ValueObject
{
    public const int MAX_LENGTH = 64;

    public string $key = '';

    public string $value = '';

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->key);
    }
}
