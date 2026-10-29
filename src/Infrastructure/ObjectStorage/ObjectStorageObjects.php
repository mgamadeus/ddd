<?php

declare(strict_types=1);

namespace DDD\Infrastructure\ObjectStorage;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The objects of a listing, in key order.
 *
 * @property ObjectStorageObject[] $elements
 * @method ObjectStorageObject|null first()
 * @method ObjectStorageObject|null getByUniqueKey(string $uniqueKey)
 * @method ObjectStorageObject[] getElements()
 */
class ObjectStorageObjects extends ObjectSet
{
    /** @return string[] The keys, in key order */
    public function getKeys(): array
    {
        return array_map(static fn(ObjectStorageObject $objectStorageObject): string => $objectStorageObject->key, array_values($this->getElements()));
    }
}
