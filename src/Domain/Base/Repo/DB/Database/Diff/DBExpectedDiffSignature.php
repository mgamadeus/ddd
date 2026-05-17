<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database\Diff;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One (sqlTableName, signature) pair captured by a frontend at view time and echoed back on the
 * apply call. The signature gate inside
 * {@see \DDD\Domain\Common\Services\DatabaseSchemaDiffService::applyDiffs()} refuses execution
 * when the freshly-recomputed signature for the table differs from the one carried here — closing
 * the gap between displayed and executed SQL.
 *
 * Carried in a {@see DBExpectedDiffSignatures} ObjectSet rather than a raw `array<string,string>`
 * map so the wire shape is typed end-to-end (deserializer enforces both keys exist, both are
 * strings) and so future evolution (capture timestamp, frontend revision id, etc.) is non-breaking.
 */
class DBExpectedDiffSignature extends ValueObject
{
    /** @var string The sqlTableName whose signature this pair carries. */
    public string $sqlTableName;

    /** @var string The sha256 diffSignature captured at view time. */
    public string $signature;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->sqlTableName);
    }
}
