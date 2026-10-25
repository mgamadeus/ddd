<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\CopyRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Entities\GeoEntities\GeoPoint;
use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * `app:db:copy-to-postgres` fixture (`agentic-plans/backend/2026-10-22-postgres-migration.md` §5.1/§5.5): one
 * column per edge case the copy and verify phases must round-trip — a nullable VARCHAR, a large TEXT, a BLOB
 * (-> bytea, hex-escaped), a boolean, a JSON column holding tabs/newlines/backslashes/unicode, a geometry POINT,
 * a VECTOR, and a nullable DATETIME used to plant a MariaDB zero-date (`0000-00-00 00:00:00`). Deliberately its
 * own fixture, not an addition to {@see \DDD\Tests\Integration\IntegrationTestCase::FIXTURE_ENTITY_CLASSES}: that
 * list feeds the T0 golden DDL compare, and adding a column to any of its three entities would change their
 * generated SQL and break the golden byte-identity gate.
 */
class CopyRow extends Entity
{
    /** @var string|null Plain nullable VARCHAR */
    public ?string $label;

    /**
     * @var string|null Large TEXT column. The auto-FULLTEXT index every TEXT column otherwise gets
     *      (Mig §3.5: "the automatic FULLTEXT index on every TEXT column is not rendered on PostgreSQL" — step 7,
     *      PostgresDdlRenderer does not yet special-case a non-Translatable TYPE_FULLTEXT index) is turned off here
     *      so this fixture only exercises what this command's build step (§5.1) actually renders.
     */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $largeText;

    /** @var string|null BLOB -> bytea, `\\x<hex>` on copy */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_BLOB)]
    public ?string $binaryData;

    /** @var bool Plain boolean */
    public bool $flag = false;

    /** @var string|null Raw JSON text (tabs/newlines/backslashes/unicode exercised by the fixture rows) */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_JSON)]
    public ?string $payload;

    /** @var GeoPoint|null Geometry column */
    public ?GeoPoint $location;

    /** @var Vector|null Vector column */
    #[DatabaseColumn(vectorDimensions: 4)]
    public ?Vector $embedding;

    /** @var DateTime|null Nullable DATETIME; one fixture row plants a MariaDB zero-date here */
    public ?DateTime $occurredAt;

    /** @var int|null Plain nullable int */
    public ?int $amount;
}
