<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types;

use Brick\Geo\IO\WKBReader;
use Brick\Geo\LineString;
use DDD\Domain\Common\Entities\Geometry\Cartesian\Point2D;
use DDD\Domain\Common\Entities\Geometry\Cartesian\Polyline;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Doctrine type mapping {@see Polyline} ↔ MySQL/MariaDB `LINESTRING` column (SRID 0 cartesian).
 *
 * Pattern mirrors {@see PointType}: WKT in, WKB out, brick/geo handles the binary read.
 * A {@see Polyline} with fewer than two vertices serialises to `null` — MySQL rejects LINESTRINGs
 * with fewer than two points.
 */
class LineStringType extends Type
{
    public const string NAME = 'cartesian_linestring';

    /** Reader is stateless; cached so it isn't reallocated per column hydration. */
    protected static ?WKBReader $wkbReader = null;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return ['linestring'];
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'LINESTRING';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Polyline
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
        if (!$geometry instanceof LineString) {
            return null;
        }
        $points = [];
        foreach ($geometry->points() as $point) {
            $points[] = new Point2D((float)$point->x(), (float)$point->y());
        }
        return new Polyline($points);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof Polyline) {
            return null;
        }
        // Delegate to the VO's __toString so the ORM persistence path produces byte-identical SQL
        // to the upsert/(string)-cast path. The VO emits invalid WKT for < 2 vertices on purpose —
        // MySQL's parser error is clearer than the cryptic "Invalid GIS data" of an empty input.
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
