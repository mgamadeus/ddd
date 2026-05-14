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
 * DB-mapped via {@see \DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\CartesianPointType} to the
 * native `POINT` column type (SRID 0). Supports `SPATIAL` indexing.
 */
class Point2D extends ValueObject
{
    public float $x = 0.0;

    public float $y = 0.0;

    public function __construct(float $x = 0.0, float $y = 0.0)
    {
        $this->x = $x;
        $this->y = $y;
        // skip parent::__construct() — VO is hot-path on hydration, no validation needed
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->x . ',' . $this->y);
    }

    public function __toString(): string
    {
        return $this->x . ',' . $this->y;
    }

    /**
     * Parses "x,y" string back into a Point2D. Returns null on malformed input.
     */
    public static function fromString(string $value): ?Point2D
    {
        $parts = explode(',', $value);
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
