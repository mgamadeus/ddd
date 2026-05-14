<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types;

use Brick\Geo\IO\WKBReader;
use Brick\Geo\Point;
use DDD\Domain\Common\Entities\Geometry\Cartesian\Point2D;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Doctrine type mapping {@see Point2D} ↔ MySQL/MariaDB `POINT` column (SRID 0 cartesian).
 *
 * Parameter value path: serialise as `'POINT(x y)'` WKT, wrap with `ST_GeomFromText(?, 0)` so the
 * database parses it into the native binary form. Read path: strip the 4-byte SRID prefix
 * MySQL prepends to the internal binary format, then parse the WKB via brick/geo's reader and
 * unwrap into a {@see Point2D} VO.
 */
class CartesianPointType extends Type
{
    public const string NAME = 'cartesian_point';

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

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Point2D
    {
        if ($value === null || !is_string($value) || $value === '') {
            return null;
        }
        // MySQL's internal spatial format prepends a 4-byte little-endian SRID before the WKB.
        $wkb = strlen($value) > 4 ? substr($value, 4) : $value;
        try {
            $geometry = (new WKBReader())->read($wkb);
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
        return sprintf('POINT(%F %F)', $value->x, $value->y);
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
