<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

/**
 * Write intents (dual-dialect architecture plan §4.6). MariaDB renders every intent but `UPDATE_PARTIAL` as its
 * golden ODKU (unchanged); PostgreSQL renders each one differently (`WriteIntentSelector`'s docblock has the
 * exact SQL shape per intent).
 */
enum WriteIntent
{
    /** No id, no resolvable unique key: `INSERT … RETURNING id`, generated id omitted. */
    case INSERT;

    /** Id present: `UPDATE … WHERE id = ? [AND <rights>] RETURNING id`; falls back to an id-carrying INSERT only
     * when there is no rights query (worker/CLI/system write) and the row is absent. */
    case UPDATE_BY_ID;

    /** `updateColumnsById()`: strict partial write of an EXISTING row — never an INSERT. */
    case UPDATE_PARTIAL;

    /** No id, a resolvable unique key: `INSERT … ON CONFLICT (<target>) DO UPDATE … RETURNING id`. */
    case UPSERT_BY_KEY;

    /** Batch `insertIgnore`: `INSERT … ON CONFLICT DO NOTHING RETURNING id`, target-less. */
    case INSERT_IF_ABSENT;
}
