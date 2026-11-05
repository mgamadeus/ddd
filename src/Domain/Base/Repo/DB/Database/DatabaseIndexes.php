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
    public function getIndexForSingleColumnName(string $columnName):?DatabaseIndex{
        return $this->getByUniqueKey(DatabaseIndex::uniqueKeyStatic($columnName));
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