<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Geometry\Cartesian;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * 2D cartesian point in arbitrary coordinate space (pixel, mm, normalised — caller decides).
 *
 * Distinct from {@see \DDD\Domain\Common\Entities\GeoEntities\GeoPoint}, which carries lat/lng on
 * the WGS84 ellipsoid (SRID 4326). Cartesian uses SRID 0 — no Earth projection, no spheroid math.
 *
 * DB-mapped via {@see \DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\PointType} to the
 * native `POINT` column type (SRID 0). Supports `SPATIAL` indexing.
 */
class Point2D extends ValueObject
{
    public float $x = 0.0;

    public float $y = 0.0;

    public function __construct(float $x = 0.0, float $y = 0.0)
    {
        parent::__construct();
        $this->x = $x;
        $this->y = $y;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->x . ',' . $this->y);
    }

    /**
     * Returns the point as a WKT `POINT(x y)` string. The Doctrine upsert path relies on this
     * representation to feed `ST_GeomFromText(?)` — same convention as brick/geo's `Point::__toString`.
     * For CSV-style stringification use {@see self::toCsv()}.
     */
    public function __toString(): string
    {
        return sprintf('POINT(%.17g %.17g)', $this->x, $this->y);
    }

    /**
     * `"x,y"` CSV representation. Useful for URL params, debug output, dump logs.
     */
    public function toCsv(): string
    {
        return $this->x . ',' . $this->y;
    }

    /**
     * Parses either `"POINT(x y)"` WKT or `"x,y"` CSV. Returns null on malformed input.
     */
    public static function fromString(string $value): ?Point2D
    {
        $trimmed = trim($value);
        if (stripos($trimmed, 'POINT(') === 0 && str_ends_with($trimmed, ')')) {
            $inner = trim(substr($trimmed, 6, -1));
            $parts = preg_split('/\s+/', $inner) ?: [];
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                return new self((float)$parts[0], (float)$parts[1]);
            }
            return null;
        }
        $parts = explode(',', $trimmed);
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return null;
        }
        return new self((float)$parts[0], (float)$parts[1]);
    }

    /**
     * Accepts `['x' => 1.0, 'y' => 2.0]` or `[1.0, 2.0]`. Convenience for JSON-decoded payloads.
     */
    public static function fromArray(array $point): ?Point2D
    {
        $x = $point['x'] ?? $point[0] ?? null;
        $y = $point['y'] ?? $point[1] ?? null;
        if (!is_numeric($x) || !is_numeric($y)) {
            return null;
        }
        return new self((float)$x, (float)$y);
    }
}
