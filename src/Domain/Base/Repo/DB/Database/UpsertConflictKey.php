<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Names the UNIQUE key PostgreSQL uses as the `ON CONFLICT` arbiter when an entity's row has more than one
 * NOT-NULL-backed UNIQUE key whose columns are ALL set at once (dual-dialect architecture plan §4.6; migration
 * plan §3.3 "Conflict target, inferred", case 4). {@see \DDD\Domain\Base\Repo\DB\Dialect\Write\ConflictTargetResolver}
 * reaches for this only once cases (1)-(3) (id set, one key left, one key with every column present) leave more
 * than one candidate.
 *
 * ## Why this exists — the MariaDB/PostgreSQL semantics difference
 *
 * MariaDB's `ON DUPLICATE KEY UPDATE` has no such ambiguity: the ENGINE fires on whichever key the incoming row
 * actually collides with. If two different existing rows each collide with the new row on a DIFFERENT one of
 * the two keys, MariaDB updates whichever one its own internal key-check order reaches first. PostgreSQL's `ON
 * CONFLICT` instead needs ONE arbiter named up front, before any row is inspected — there is no "whichever key
 * collides" form.
 *
 * Declaring this attribute makes the chosen key authoritative for EVERY ambiguous row of this entity: a
 * collision on the declared key is absorbed into an `UPDATE`, exactly like MariaDB; a collision on the OTHER
 * (non-declared) candidate key instead raises a real `23505` unique-violation that MariaDB would have silently
 * merged. This is a deliberate, documented behaviour difference, not a bug — pick the key whose collisions this
 * entity's own write path actually relies on being merged (e.g. `DeviceRegistration`'s `(tokenHash, audience)`,
 * the key its `register()` re-points on when a device changes owner — never its `uuid` public handle, a
 * collision on which would mean two different devices generated the same opaque id, a bug to surface, not hide).
 *
 * `columns` must name the UNIQUE columns of a key already declared via {@see DatabaseIndex} (`TYPE_UNIQUE`) on
 * this same class — the same column set, in any order, never a new index. The resolver fails loudly if no
 * UNIQUE index with exactly those columns exists among the row's ambiguous candidates, so a typo or a renamed
 * column is caught at the first write instead of silently falling through to the wrong key.
 *
 * No MariaDB effect.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class UpsertConflictKey extends ValueObject
{
    use BaseAttributeTrait;

    /** @var string[] Unquoted column names of the declared UNIQUE key to use as the `ON CONFLICT` arbiter. */
    public array $columns;

    /** @param string[] $columns */
    public function __construct(array $columns)
    {
        $this->columns = $columns;
        parent::__construct();
    }
}
