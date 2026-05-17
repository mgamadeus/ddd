<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Collection of {@see DBCopyForcingOperation}. Populated by the production guard when the diff
 * contains operations that would force ALGORITHM=COPY on a large table. Empty (or null on the
 * parent diff) when the table is below the size/row thresholds or the operations are all in-place.
 *
 * @property DBCopyForcingOperation[] $elements
 * @method DBCopyForcingOperation first()
 * @method DBCopyForcingOperation[] getElements()
 */
class DBCopyForcingOperations extends ObjectSet
{
    /**
     * Convenience projection used by the production-guard message builder.
     *
     * @return string[]
     */
    public function toDescriptionList(): array
    {
        $out = [];
        foreach ($this->elements as $op) {
            $out[] = $op->description;
        }
        return $out;
    }

    /**
     * @param string[] $descriptions
     */
    public static function fromDescriptionList(array $descriptions): self
    {
        $set = new self();
        foreach ($descriptions as $description) {
            $op = new DBCopyForcingOperation();
            $op->description = $description;
            $set->add($op);
        }
        return $set;
    }
}
