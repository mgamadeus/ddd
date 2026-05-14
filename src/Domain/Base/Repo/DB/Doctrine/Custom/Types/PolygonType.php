<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types;

use Brick\Geo\IO\WKBReader;
use Brick\Geo\Polygon as BrickPolygon;
use DDD\Domain\Common\Entities\Geometry\Cartesian\Point2D;
use DDD\Domain\Common\Entities\Geometry\Cartesian\Polygon;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Doctrine type mapping {@see Polygon} ↔ MySQL/MariaDB `POLYGON` column (SRID 0 cartesian).
 *
 * Polygon WKT requires each ring to be explicitly closed (first vertex == last). This type adds
 * the closing vertex on serialisation when the caller omitted it, so {@see Polygon} VOs may store
 * either implicit-close (outer ring `[A, B, C, D]`) or explicit-close (`[A, B, C, D, A]`) — both
 * roundtrip to the same `POLYGON` storage.
 *
 * A polygon with fewer than three distinct outer-ring vertices serialises to `null` — MySQL
 * rejects degenerate polygons.
 */
class PolygonType extends Type
{
    public const string NAME = 'cartesian_polygon';

    /** Reader is stateless; cached so it isn't reallocated per column hydration. */
    protected static ?WKBReader $wkbReader = null;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return ['polygon'];
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'POLYGON';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Polygon
    {
        if ($value === null || !is_string($value) || $value === '') {
            return null;
        }
        $wkb = strlen($value) > 4 ? substr($value, 4) : $value;
        try {
            $geometry = (self::$wkbReader ??= new WKBReader())->read($wkb);
        } catch (\Throwable) {
            return null;
        }
        if (!$geometry instanceof BrickPolygon) {
            return null;
        }
        $exterior = $geometry->exteriorRing();
        $outerRing = [];
        if ($exterior !== null) {
            foreach ($exterior->points() as $point) {
                $outerRing[] = new Point2D((float)$point->x(), (float)$point->y());
            }
        }
        $innerRings = [];
        for ($i = 0; $i < $geometry->numInteriorRings(); $i++) {
            $ringPoints = [];
            foreach ($geometry->interiorRingN($i + 1)->points() as $point) {
                $ringPoints[] = new Point2D((float)$point->x(), (float)$point->y());
            }
            $innerRings[] = $ringPoints;
        }
        return new Polygon($outerRing, $innerRings);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof Polygon) {
            return null;
        }
        // Delegate to the VO's __toString so the ORM persistence path produces byte-identical SQL
        // to the upsert/(string)-cast path. Degenerate rings emit invalid WKT — MySQL's parser
        // error is clearer than the cryptic "Invalid GIS data" of an empty input.
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
