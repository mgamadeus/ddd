<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Identifiers;

use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * D1 (dual-dialect architecture plan `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md` §4.5):
 * PostgreSQL identifier naming. Unlike MariaDB (where an index name is scoped to its own table, so
 * `DatabaseIndex::getSql()`'s bare `uniq_locationId` never collides across tables), PostgreSQL index and constraint
 * names are **schema-wide unique**. Every name this policy produces is therefore table-prefixed:
 *
 * - index: `<Table>_<name>` (`<name>` = {@see \DDD\Domain\Base\Repo\DB\Database\DatabaseIndex::getIndexNameBase()},
 *   the same type-abbreviation + column-shorthand vocabulary MariaDB uses, just without MariaDB's 64-char cap).
 * - foreign key: `<Table>_fk_<col>` (table name first, "fk" second — the opposite order from MariaDB's
 *   `fk_{table}_{col}` — so that a schema-wide alphabetical listing groups every object by table).
 *
 * A name over PostgreSQL's 63-byte (`NAMEDATALEN - 1`) identifier limit is cut to 54 bytes of the original head +
 * `_` + an 8-hex-char sha256 suffix of the FULL original name (54 + 1 + 8 = 63), deterministic so re-running the
 * generator never ping-pongs a rename. One `IdentifierPolicy` instance covers exactly one render run (one call to
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\Schema\PostgresDdlRenderer::renderCreate()} across a full entity set, or
 * one console-command invocation): it remembers every name it has handed out and FAILS FAST on a schema-wide
 * duplicate — two different source names that collide after prefixing/shortening — instead of silently emitting a
 * second `CREATE INDEX` PostgreSQL would then reject at apply time with a confusing "already exists" error.
 */
class IdentifierPolicy
{
    /** PostgreSQL's NAMEDATALEN is 64, so the last usable identifier byte is index 62 (63 bytes total). */
    public const int MAX_LENGTH = 63;

    protected const int HASH_SUFFIX_LENGTH = 8;

    protected const int HEAD_LENGTH = self::MAX_LENGTH - self::HASH_SUFFIX_LENGTH - 1;

    /** @var array<string, string> shortened/final name => the full pre-shorten name that produced it */
    protected array $issuedNames = [];

    public function indexName(string $table, string $indexNameBase): string
    {
        return $this->resolve("{$table}_{$indexNameBase}");
    }

    public function foreignKeyName(string $table, string $internalIdColumn): string
    {
        return $this->resolve("{$table}_fk_{$internalIdColumn}");
    }

    /** Lets a caller reserve/validate an arbitrary schema-wide name through the same duplicate check (e.g. a table name). */
    public function reserve(string $name): string
    {
        return $this->resolve($name);
    }

    protected function resolve(string $fullName): string
    {
        $final = strlen($fullName) <= self::MAX_LENGTH ? $fullName : $this->shorten($fullName);
        $this->assertNotADuplicate($final, $fullName);
        $this->issuedNames[$final] = $fullName;
        return $final;
    }

    protected function shorten(string $fullName): string
    {
        $hashSuffix = substr(hash('sha256', $fullName), 0, self::HASH_SUFFIX_LENGTH);
        return substr($fullName, 0, self::HEAD_LENGTH) . '_' . $hashSuffix;
    }

    protected function assertNotADuplicate(string $final, string $fullName): void
    {
        if (isset($this->issuedNames[$final]) && $this->issuedNames[$final] !== $fullName) {
            throw new InternalErrorException(
                "PostgreSQL identifier \"$final\" is a schema-wide duplicate: both \"{$this->issuedNames[$final]}\" "
                . "and \"$fullName\" resolve to it. Rename one of the underlying indexes/columns/tables, or shorten "
                . 'one explicitly (the dual-dialect architecture plan §4.5 requires the generator to fail here rather '
                . 'than silently drop one of the two objects.)'
            );
        }
    }
}
