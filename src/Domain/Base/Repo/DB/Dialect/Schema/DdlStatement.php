<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Schema;

/**
 * One executable DDL statement, dialect-agnostic (dual-dialect architecture plan
 * `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md` §4.10/§6 row 4a). A {@see DdlRenderer} hands
 * out a list of these instead of one SQL blob so a caller can batch, reorder, or inspect what it is about to run
 * without parsing SQL text.
 *
 * `phase` + `table` enable **cross-table phase batching**: rendering a whole entity set runs every table's `phase`
 * before any table's `phase`-successor (every `CREATE_TABLE` before any `CREATE_INDEX`, every `CREATE_INDEX` before
 * any `CREATE_FOREIGN_KEY`) via {@see sortByPhase()}, which is what lets a foreign key reference a table created
 * later in entity-class scan order. A single model's own `renderCreate()` list is already safe to run standalone in
 * the order returned (table, then its indexes, then its foreign keys) without this sort.
 *
 * `lockClass` and `transactional` are carried as data for the future production guard / `CONCURRENTLY` handling
 * (ALTER-path work, build step 7+); the create-path renderers in step 4a only ever produce `LOCK_NONE` /
 * `transactional: true` statements (brand-new objects, nothing to lock, no `CONCURRENTLY` index needed on an empty
 * table) but the fields exist now so step 7 is additive, not a reshape of callers already holding a `DdlStatement[]`.
 */
final readonly class DdlStatement
{
    // ─── Phases, in CREATE-path execution order (§6 row 4a scope: create path only) ───
    public const string PHASE_EXTENSION = 'EXTENSION';

    public const string PHASE_COLLATION = 'COLLATION';

    public const string PHASE_FUNCTION = 'FUNCTION';

    public const string PHASE_CREATE_TABLE = 'CREATE_TABLE';

    public const string PHASE_CREATE_INDEX = 'CREATE_INDEX';

    public const string PHASE_CREATE_FOREIGN_KEY = 'CREATE_FOREIGN_KEY';

    /**
     * @var string[] Execution order for {@see sortByPhase()}. Step 7 (ALTER/DROP phases, aligned with
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
    ];

    // ─── Lock classes (production-guard data, not yet acted on in step 4a) ───
    public const string LOCK_NONE = 'NONE';

    public const string LOCK_METADATA_ONLY = 'METADATA_ONLY';

    public const string LOCK_SHARE = 'SHARE';

    public const string LOCK_ACCESS_EXCLUSIVE = 'ACCESS_EXCLUSIVE';

    public function __construct(
        public string $sql,
        public string $phase,
        public string $lockClass = self::LOCK_NONE,
        public bool $transactional = true,
        public ?string $table = null,
    ) {
    }

    /**
     * Stable sort by {@see CREATE_PHASE_ORDER}; statements with an unrecognised phase sort last, in encounter
     * order. Does not rely on PHP's sort stability (explicit position tie-break) so behaviour is the same on any
     * supported PHP version.
     *
     * @param DdlStatement[] $statements
     * @return DdlStatement[]
     */
    public static function sortByPhase(array $statements): array
    {
        $rank = array_flip(self::CREATE_PHASE_ORDER);
        $unknownRank = count(self::CREATE_PHASE_ORDER);

        $decorated = [];
        foreach (array_values($statements) as $position => $statement) {
            $decorated[] = [$rank[$statement->phase] ?? $unknownRank, $position, $statement];
        }
        usort($decorated, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return array_map(static fn (array $entry) => $entry[2], $decorated);
    }
}
