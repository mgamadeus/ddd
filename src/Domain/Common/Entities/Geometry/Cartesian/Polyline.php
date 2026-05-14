<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Geometry\Cartesian;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Open 2D cartesian polyline: an ordered list of {@see Point2D} vertices. First and last vertex
 * are NOT conceptually connected — for closed shapes use {@see Polygon}.
 *
 * DB-mapped via {@see \DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\LineStringType} to
 * the native `LINESTRING` column type (SRID 0). Supports `SPATIAL` indexing.
 *
 * Typical uses: free-form drawing strokes, signature paths, route segments in pixel space, fences
 * separating zones in a floor plan.
 */
class Polyline extends ValueObject
{
    /** @var Point2D[] Ordered list of vertices */
    public array $points = [];

    public function __construct(array $points = [])
    {
        foreach ($points as $point) {
            if ($point instanceof Point2D) {
                $this->points[] = $point;
            } elseif (is_array($point)) {
                $parsed = Point2D::fromArray($point);
                if ($parsed !== null) {
                    $this->points[] = $parsed;
                }
            }
        }
    }

    public function uniqueKey(): string
    {
        $parts = [];
        foreach ($this->points as $point) {
            $parts[] = $point->x . ',' . $point->y;
        }
        return self::uniqueKeyStatic(implode('|', $parts));
    }

    public function count(): int
    {
        return count($this->points);
    }

    public function isEmpty(): bool
    {
        return $this->points === [];
    }

    /**
     * Returns the polyline as WKT `LINESTRING(x1 y1, x2 y2, ...)`. The Doctrine upsert path feeds
     * this directly into `ST_GeomFromText(?)`. Empty polylines or polylines with fewer than two
     * vertices return an empty string — MySQL rejects LINESTRINGs with < 2 points.
     */
    public function __toString(): string
    {
        if (count($this->points) < 2) {
            return '';
        }
        $vertices = [];
        foreach ($this->points as $point) {
            $vertices[] = sprintf('%F %F', $point->x, $point->y);
        }
        return 'LINESTRING(' . implode(', ', $vertices) . ')';
    }
}
