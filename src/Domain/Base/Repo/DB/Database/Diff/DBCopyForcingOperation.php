<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One operation within a diff that forces ALGORITHM=COPY under MariaDB/Galera. Attached to
 * {@see DBTableDiff::$copyForcingOperations} (when populated by the production guard) so the
 * frontend can render each risk as its own bullet rather than parsing the multi-line
 * {@see DBTableDiff::$directApplyBlockReason} prose.
 */
class DBCopyForcingOperation extends ValueObject
{
    /** @var string Human-readable description of the COPY-forcing operation. */
    public string $description;

    /**
     * Two operations with identical descriptions (e.g. two MODIFY-column ops on different columns
     * that happen to produce the same rendered text) must both survive `ObjectSet::add()`. Key
     * by PHP object identity to bypass the set's content-equality dedup.
     */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
