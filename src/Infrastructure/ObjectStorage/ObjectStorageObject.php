<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * One object of a listing: its key (without the store's prefix), size and last modification.
 */
class ObjectStorageObject extends ValueObject
{
    public string $key = '';

    /** @var int|null Size in bytes */
    public ?int $size = null;

    public ?DateTime $lastModified = null;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->key);
    }
}
