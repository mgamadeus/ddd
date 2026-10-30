<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types;

use Brick\Geo\IO\WKBReader;
use Brick\Geo\Point;
use DDD\Domain\Common\Entities\Geometry\Cartesian\Point2D;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Doctrine type mapping {@see Point2D} ↔ a `POINT` column (SRID 0 cartesian), MySQL/MariaDB or PostgreSQL/PostGIS.
 *
 * Parameter value path: serialise as `'POINT(x y)'` WKT, wrap with `ST_GeomFromText(?, 0)` so the
 * database parses it into the native binary form — identical on both engines (PostGIS also provides
 * `ST_GeomFromText`). Read path diverges (dual-dialect architecture plan §4.9): MariaDB's internal spatial
 * storage format prepends a 4-byte little-endian SRID before the WKB, which {@see self::convertToPHPValue()}
 * strips unconditionally (unchanged, golden-sensitive); PostgreSQL has no such prefix on the wire, so
 * {@see self::convertToPHPValueSQL()} wraps the column with `ST_AsBinary()` there (brick/geo-doctrine's own
 * {@see \Brick\Geo\Doctrine\Types\GeometryType} pattern) to get pure WKB, read back via brick/geo's reader.
 */
class PointType extends Type
{
    public const string NAME = 'cartesian_point';

    /** Reader is stateless; cached so it isn't reallocated per column hydration. */
    protected static ?WKBReader $wkbReader = null;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return ['point'];
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'POINT';
    }

    /** PostgreSQL only (§4.9): pure WKB via `ST_AsBinary()`, no SRID prefix to strip. MariaDB keeps the bare column. */
    public function convertToPHPValueSQL($sqlExpr, $platform): string
    {
        if ($platform instanceof PostgreSQLPlatform) {
            return sprintf('ST_AsBinary(%s)', $sqlExpr);
        }
        return $sqlExpr;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Point2D
    {
        if ($value === null) {
            return null;
        }
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        // MySQL's internal spatial format prepends a 4-byte little-endian SRID before the WKB; PostgreSQL's
        // ST_AsBinary() (applied above) already returns pure WKB with no such prefix.
        $wkb = (!$platform instanceof PostgreSQLPlatform && strlen($value) > 4) ? substr($value, 4) : $value;
        try {
            $geometry = (self::$wkbReader ??= new WKBReader())->read($wkb);
        } catch (\Throwable) {
            return null;
        }
        if (!$geometry instanceof Point) {
            return null;
        }
        return new Point2D((float)$geometry->x(), (float)$geometry->y());
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof Point2D) {
            return null;
        }
        // Delegate to the VO's __toString so the ORM persistence path produces byte-identical SQL
        // to the upsert/(string)-cast path.
        return (string)$value;
    }

    public function canRequireSQLConversion(): bool
    {
        return true;
    }

    public function convertToDatabaseValueSQL($sqlExpr, AbstractPlatform $platform): string
    {
        return sprintf('ST_GeomFromText(%s, 0)', $sqlExpr);
    }
}
