<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Schema;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One executable DDL statement, dialect-agnostic (dual-dialect architecture plan
 * `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md` §4.10/§6 row 4a). A {@see DdlRenderer} hands
 * out a list of these instead of one SQL blob so a caller can batch, reorder, or inspect what it is about to run
 * without parsing SQL text.
 *
 * `phase` + `table` enable **cross-table phase batching**: rendering a whole entity set runs every table's `phase`
 * before any table's `phase`-successor (every `CREATE_TABLE` before any `CREATE_INDEX`, every `CREATE_INDEX` before
 * any `CREATE_FOREIGN_KEY`) via {@see DdlStatements::sortedByPhase()}, which is what lets a foreign key reference a table created
 * later in entity-class scan order. A single model's own `renderCreate()` list is already safe to run standalone in
 * the order returned (table, then its indexes, then its foreign keys) without this sort.
 *
 * `lockClass` and `transactional` are carried as data for the future production guard / `CONCURRENTLY` handling
 * (ALTER-path work, build step 7+); the create-path renderers in step 4a only ever produce `LOCK_NONE` /
 * `transactional: true` statements (brand-new objects, nothing to lock, no `CONCURRENTLY` index needed on an empty
 * table) but the fields exist now so step 7 is additive, not a reshape of callers already holding a `DdlStatement[]`.
 */
class DdlStatement extends ValueObject
{
    // ─── Phases, in CREATE-path execution order (§6 row 4a scope: create path only) ───
    public const string PHASE_EXTENSION = 'EXTENSION';

    public const string PHASE_COLLATION = 'COLLATION';

    public const string PHASE_FUNCTION = 'FUNCTION';

    public const string PHASE_CREATE_TABLE = 'CREATE_TABLE';

    public const string PHASE_CREATE_INDEX = 'CREATE_INDEX';

    public const string PHASE_CREATE_FOREIGN_KEY = 'CREATE_FOREIGN_KEY';

    /**
     * `DatabaseTrigger` per dialect (Arch §4.10): last of the CREATE-path phases, deliberately — a copy/migration
     * caller (`app:db:copy-to-postgres` finalise) must create every trigger only after a table's data phase has
     * finished, since e.g. the Radbonus Track trigger refuses a row the bulk copy would otherwise insert
     * unconditionally.
     */
    public const string PHASE_CREATE_TRIGGER = 'CREATE_TRIGGER';

    /**
     * @var string[] Execution order for {@see DdlStatements::sortedByPhase()}. Step 7 (ALTER/DROP phases, aligned with
     *      `DatabaseSchemaDiffService`'s 13-phase assembler where it makes sense) extends this list; it does not
     *      reorder the entries already here.
     */
    public const array CREATE_PHASE_ORDER = [
        self::PHASE_EXTENSION,
        self::PHASE_COLLATION,
        self::PHASE_FUNCTION,
        self::PHASE_CREATE_TABLE,
        self::PHASE_CREATE_INDEX,
        self::PHASE_CREATE_FOREIGN_KEY,
        self::PHASE_CREATE_TRIGGER,
    ];

    // ─── Lock classes (production-guard data, not yet acted on in step 4a) ───
    public const string LOCK_NONE = 'NONE';

    public const string LOCK_METADATA_ONLY = 'METADATA_ONLY';

    public const string LOCK_SHARE = 'SHARE';

    public const string LOCK_ACCESS_EXCLUSIVE = 'ACCESS_EXCLUSIVE';

    public function __construct(
        public readonly string $sql,
        public readonly string $phase,
        public readonly string $lockClass = self::LOCK_NONE,
        public readonly bool $transactional = true,
        public readonly ?string $table = null,
    ) {
        parent::__construct();
    }

    /** Identical statements may legitimately repeat and order matters: every instance is its own element. */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
