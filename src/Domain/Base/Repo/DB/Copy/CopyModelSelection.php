<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Copy;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModels;

/**
 * Which entity tables `app:db:copy-to-postgres` copies, and which source tables it skips for want of an entity.
 */
class CopyModelSelection extends ValueObject
{
    /**
     * @param DatabaseModels $databaseModels The models to copy (already filtered by --table).
     * @param string[] $unmatchedSourceTables Source tables with no matching entity.
     */
    public function __construct(
        public readonly DatabaseModels $databaseModels,
        public readonly array $unmatchedSourceTables = [],
    ) {
        parent::__construct();
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
