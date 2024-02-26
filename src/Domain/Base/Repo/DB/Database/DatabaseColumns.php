<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @method DatabaseModel getParent()
 * @property DatabaseColumn[] $elements;
 * @method DatabaseColumn getByUniqueKey(string $uniqueKey)
 * @method DatabaseColumn first()
 * @method DatabaseColumn[] getElements()
 */
class DatabaseColumns extends ObjectSet
{
    /**
     * Return colum with primary key
     * @return DatabaseColumn|null
     */
    public function getPrimaryKeyColumn(): ?DatabaseColumn
    {
        foreach ($this->getElements() as $column) {
            if ($column->isPrimaryKey) {
                return $column;
            }
        }
        return null;
    }

    /**
     * Return column by name
     * @param string $columnName
     * @return DatabaseColumn|null
     */
    public function getColumnByName(string $columnName):?DatabaseColumn{
        return $this->getByUniqueKey(DatabaseColumn::uniqueKeyStatic($columnName));
    }
}