<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @method DatabaseOneToManyRelationship getParent()
 * @property DatabaseOneToManyRelationship[] $elements;
 * @method DatabaseOneToManyRelationship getByUniqueKey(string $uniqueKey)
 * @method DatabaseOneToManyRelationship first()
 * @method DatabaseOneToManyRelationship[] getElements()
 */
class DatabaseOneToManyRelationships extends ObjectSet
{
}