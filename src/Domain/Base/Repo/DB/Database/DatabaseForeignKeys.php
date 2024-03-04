<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Entities\BaseObject;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * @method DatabaseModel getParent()
 * @property DatabaseForeignKey[] $elements;
 * @method DatabaseForeignKey getByUniqueKey(string $uniqueKey)
 * @method DatabaseForeignKey first()
 * @method DatabaseForeignKey[] getElements()
 */
class DatabaseForeignKeys extends ObjectSet
{
    /** @var DatabaseForeignKey[][] */
    private array $foreignKeysByForeignModelClassName = [];

    public function add(?BaseObject &...$elements): void
    {
        foreach ($elements as $element) {
            /** @var DatabaseForeignKey $element */
            if (!isset($this->foreignKeysByTargetEntity[$element->foreignModelClassName])) {
                $this->foreignKeysByForeignModelClassName[$element->foreignModelClassName] = [];
            }
            $this->foreignKeysByForeignModelClassName[$element->foreignModelClassName][] = $element;
        }
        parent::add(...$elements);
    }

    public function getDatabaseForeignKeyByForeignModelName(string $foreiugnModelName): ?DatabaseForeignKey
    {
        $foreignKeys = $this->foreignKeysByForeignModelClassName[$foreiugnModelName] ?? null;
        if (!$foreignKeys) {
            return null;
        }
        if ($foreignKeys && count($foreignKeys) > 1) {
            $keys = [];
            foreach ($foreignKeys as $foreignKey){
                $keys[] = $foreignKey->internalIdColumn;
            }
            throw new InternalErrorException('More than one foreign key referencing '. $foreiugnModelName . ' ('.implode(',',$keys).')');
        }
        return $foreignKeys[0];
    }

}