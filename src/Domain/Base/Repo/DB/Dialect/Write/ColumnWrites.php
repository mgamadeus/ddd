<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The {@see ColumnWrite}s of one row, in declaration order ({@see UpsertSpec::$columns}).
 *
 * @property ColumnWrite[] $elements
 * @method ColumnWrite|null first()
 * @method ColumnWrite[] getElements()
 */
class ColumnWrites extends ObjectSet
{
    /**
     * @param ColumnWrite ...$columnWrites
     * @return static
     */
    public static function fromColumnWrites(ColumnWrite ...$columnWrites): static
    {
        $set = new static();
        foreach ($columnWrites as $columnWrite) {
            $set->add($columnWrite);
        }
        return $set;
    }

    /** @return string[] The column names, in order */
    public function getColumnNames(): array
    {
        return array_map(static fn(ColumnWrite $columnWrite): string => $columnWrite->columnName, $this->elements);
    }
}
