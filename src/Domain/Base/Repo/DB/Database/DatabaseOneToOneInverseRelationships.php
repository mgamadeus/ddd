<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @method DatabaseOneToOneInverseRelationship getParent()
 * @property DatabaseOneToOneInverseRelationship[] $elements;
 * @method DatabaseOneToOneInverseRelationship getByUniqueKey(string $uniqueKey)
 * @method DatabaseOneToOneInverseRelationship first()
 * @method DatabaseOneToOneInverseRelationship[] getElements()
 */
class DatabaseOneToOneInverseRelationships extends ObjectSet
{
}
