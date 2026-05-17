<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Snapshot of live-table size statistics used by the production guard. Returned by
 * {@see \DDD\Domain\Common\Services\DatabaseSchemaDiffService::getTableSizeStats()}. Both fields
 * may be null when the table does not yet exist (CREATE_TABLE) or `INFORMATION_SCHEMA.TABLES` did
 * not return a row (unusual — usually means the introspection cache was invalidated mid-flight).
 */
class DBTableSizeStats extends ValueObject
{
    /** @var int|null Total table size (data + index) in MB. */
    public ?int $sizeMb = null;

    /** @var int|null InnoDB-estimated row count (NOT a COUNT(*)). */
    public ?int $rowCount = null;

    /**
     * True when the stats exceed EITHER the size threshold OR the row threshold. Both axes matter
     * for production-guard classification — size drives TOI block duration, row count drives
     * Galera flow-control pressure. When both fields are null (stats lookup failed for a missing
     * table), the snapshot can't be classified and the predicate returns false.
     *
     * Belongs on the value object rather than the service so the snapshot can answer the question
     * it was built to answer — see DDD ValueObject conventions.
     */
    public function isLarge(int $sizeThresholdMb, int $rowThreshold): bool
    {
        if ($this->sizeMb === null && $this->rowCount === null) {
            return false;
        }
        return ($this->sizeMb !== null && $this->sizeMb > $sizeThresholdMb)
            || ($this->rowCount !== null && $this->rowCount > $rowThreshold);
    }
}
