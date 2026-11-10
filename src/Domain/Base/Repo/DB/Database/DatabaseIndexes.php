<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @method DatabaseModel getParent()
 * @property DatabaseIndex[] $elements;
 * @method DatabaseIndex getByUniqueKey(string $uniqueKey)
 * @method DatabaseIndex first()
 * @method DatabaseIndex[] getElements()
 */
class DatabaseIndexes extends ObjectSet
{
    /**
     * @param string $columnName
     * @return DatabaseIndex|null The plain or unique index over exactly this one column, the unique one first. An
     * index over several columns is not returned, even when it starts with the column
     */
    public function getIndexForSingleColumnName(string $columnName): ?DatabaseIndex
    {
        $indexForSingleColumn = null;
        foreach ($this->getElements() as $databaseIndex) {
            if ($databaseIndex->indexColumns !== [$columnName]) {
                continue;
            }
            if ($databaseIndex->indexType === DatabaseIndex::TYPE_UNIQUE) {
                return $databaseIndex;
            }
            if ($databaseIndex->indexType === DatabaseIndex::TYPE_INDEX) {
                $indexForSingleColumn ??= $databaseIndex;
            }
        }
        return $indexForSingleColumn;
    }

    /**
     * Removes the plain index over exactly this one column: a unique index over the same column serves every read
     * the plain one would.
     *
     * @param string $columnName
     */
    public function removePlainIndexOfSingleColumn(string $columnName): void
    {
        foreach ($this->getElements() as $databaseIndex) {
            if ($databaseIndex->indexType === DatabaseIndex::TYPE_INDEX && $databaseIndex->indexColumns === [$columnName]) {
                $this->remove($databaseIndex);
            }
        }
    }

    /**
     * Gives every index of the table a distinct name. Composite index names abbreviate each column to three
     * characters, so two different indexes can get one name — MariaDB then skips the second (`CREATE INDEX IF NOT
     * EXISTS`), PostgreSQL refuses it. The first index keeps its name (existing databases keep theirs); each later
     * one with a name already taken gets a suffix derived from its columns. Names compare as MariaDB writes them
     * (capped at 64 characters).
     */
    public function assignDistinctIndexNames(): void
    {
        $takenIndexNames = [];
        foreach ($this->getElements() as $databaseIndex) {
            $indexName = strtolower(DatabaseIdentifier::shortenToMaxLength($databaseIndex->getIndexNameBase()));
            if (isset($takenIndexNames[$indexName])) {
                $databaseIndex->setIndexNameSuffixFromColumns();
                $indexName = strtolower(DatabaseIdentifier::shortenToMaxLength($databaseIndex->getIndexNameBase()));
            }
            $takenIndexNames[$indexName] = true;
        }
    }
}