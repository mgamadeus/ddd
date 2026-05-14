<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types;

use Brick\Geo\IO\WKBReader;
use Brick\Geo\Polygon as BrickPolygon;
use DDD\Domain\Common\Entities\Geometry\Cartesian\BoundingBox2D;
use DDD\Domain\Common\Entities\Geometry\Cartesian\Point2D;
use DDD\Domain\Common\Entities\Geometry\Cartesian\Polygon;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Doctrine type mapping {@see BoundingBox2D} ↔ MySQL/MariaDB `POLYGON` column (SRID 0 cartesian).
 *
 * Internally serialises to the same four-vertex closed POLYGON shape as
 * {@see CartesianPolygonType}, so existing spatial operators (`ST_Intersects`, `ST_Within`, …)
 * work against bounding-box columns identically. On read, parses the polygon and folds it back
 * into the `(x, y, width, height)` VO — returns `null` when the column doesn't hold an
 * axis-aligned rectangle.
 */
class CartesianBoundingBoxType extends Type
{
    public const string NAME = 'cartesian_bbox';

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

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?BoundingBox2D
    {
        if ($value === null || !is_string($value) || $value === '') {
            return null;
        }
        $wkb = strlen($value) > 4 ? substr($value, 4) : $value;
        try {
            $geometry = (new WKBReader())->read($wkb);
        } catch (\Throwable) {
            return null;
        }
        if (!$geometry instanceof BrickPolygon) {
            return null;
        }
        $exterior = $geometry->exteriorRing();
        if ($exterior === null) {
            return null;
        }
        $outerRing = [];
        foreach ($exterior->points() as $point) {
            $outerRing[] = new Point2D((float)$point->x(), (float)$point->y());
        }
        return BoundingBox2D::fromPolygon(new Polygon($outerRing));
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null || !$value instanceof BoundingBox2D || $value->isEmpty()) {
            return null;
        }
        $x = $value->x;
        $y = $value->y;
        $mx = $value->maxX();
        $my = $value->maxY();
        return sprintf(
            'POLYGON((%F %F, %F %F, %F %F, %F %F, %F %F))',
            $x, $y, $mx, $y, $mx, $my, $x, $my, $x, $y
        );
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
