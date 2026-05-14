<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Geometry\Cartesian;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Closed 2D cartesian polygon: an outer ring plus zero or more inner rings (holes).
 *
 * The polygon is implicitly closed — the first and last vertex of every ring are joined by an
 * edge. Callers may either omit the duplicate closing vertex or include it; both are accepted.
 * Inner rings model holes punched out of the outer ring.
 *
 * DB-mapped via {@see \DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\PolygonType} to the
 * native `POLYGON` column type (SRID 0). Supports `SPATIAL` indexing — `ST_Contains` /
 * `ST_Intersects` / `ST_Within` all work against the column directly.
 *
 * For axis-aligned rectangles use {@see BoundingBox2D} instead — semantically tighter API and
 * roundtrips through the same `POLYGON` storage.
 */
class Polygon extends ValueObject
{
    /** @var Point2D[] Outer ring vertices, in order. */
    public array $outerRing = [];

    /** @var Point2D[][] Inner rings (holes). Empty array when polygon has no holes. */
    public array $innerRings = [];

    /**
     * @param array<int, Point2D|array> $outerRing
     * @param array<int, array<int, Point2D|array>> $innerRings
     */
    public function __construct(array $outerRing = [], array $innerRings = [])
    {
        parent::__construct();
        $this->outerRing = $this->normaliseRing($outerRing);
        foreach ($innerRings as $ring) {
            $normalised = $this->normaliseRing($ring);
            if ($normalised !== []) {
                $this->innerRings[] = $normalised;
            }
        }
    }

    /**
     * @param array<int, Point2D|array> $ring
     * @return Point2D[]
     */
    protected function normaliseRing(array $ring): array
    {
        $points = [];
        foreach ($ring as $point) {
            if ($point instanceof Point2D) {
                $points[] = $point;
            } elseif (is_array($point)) {
                $parsed = Point2D::fromArray($point);
                if ($parsed !== null) {
                    $points[] = $parsed;
                }
            }
        }
        return $points;
    }

    public function uniqueKey(): string
    {
        $parts = [];
        $parts[] = $this->ringToString($this->outerRing);
        foreach ($this->innerRings as $ring) {
            $parts[] = $this->ringToString($ring);
        }
        return self::uniqueKeyStatic(implode(';', $parts));
    }

    /**
     * @param Point2D[] $ring
     */
    protected function ringToString(array $ring): string
    {
        $segments = [];
        foreach ($ring as $point) {
            $segments[] = $point->x . ',' . $point->y;
        }
        return implode('|', $segments);
    }

    public function isEmpty(): bool
    {
        return $this->outerRing === [];
    }

    /**
     * Returns the polygon as WKT `POLYGON((outerRing), (innerRing1), ...)`. Each ring is
     * explicitly closed — if the caller omitted the duplicate closing vertex, this method
     * appends it. Degenerate rings (< 3 vertices) emit `()` and degenerate polygons emit
     * `POLYGON()` — both are invalid WKT and MySQL rejects with a clear parser error,
     * preferable to the cryptic "Invalid GIS data" returned for empty input.
     */
    public function __toString(): string
    {
        $rings = [self::ringToWkt($this->outerRing)];
        foreach ($this->innerRings as $ring) {
            $rings[] = self::ringToWkt($ring);
        }
        return 'POLYGON(' . implode(', ', $rings) . ')';
    }

    /**
     * @param Point2D[] $ring
     */
    protected static function ringToWkt(array $ring): string
    {
        if ($ring === []) {
            return '()';
        }
        $vertices = [];
        foreach ($ring as $point) {
            $vertices[] = sprintf('%.17g %.17g', $point->x, $point->y);
        }
        $first = $ring[0];
        $last = $ring[count($ring) - 1];
        if ($first->x !== $last->x || $first->y !== $last->y) {
            $vertices[] = sprintf('%.17g %.17g', $first->x, $first->y);
        }
        return '(' . implode(', ', $vertices) . ')';
    }
}
