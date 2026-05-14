<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Geometry\Cartesian;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Axis-aligned 2D bounding box defined by its top-left corner and dimensions.
 *
 * Conventionally `(x, y)` is the minimum corner and dimensions extend toward the positive axes,
 * but the API does not enforce a coordinate orientation — interpret consistently per project.
 *
 * DB-mapped via {@see \DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\BoundingBoxType}
 * to the native `POLYGON` column type as a closed four-vertex rectangle. This trades ~30 bytes
 * vs four floats for a fully spatial-indexable column — `ST_Intersects(bb, otherShape)` works
 * directly against the column. Use four columns if you need raw float speed and never spatial-query.
 *
 * Convertible to a generic {@see Polygon} via {@see self::toPolygon()} when caller-side spatial
 * functions need the more general type.
 */
class BoundingBox2D extends ValueObject
{
    public float $x = 0.0;

    public float $y = 0.0;

    public float $width = 0.0;

    public float $height = 0.0;

    public function __construct(float $x = 0.0, float $y = 0.0, float $width = 0.0, float $height = 0.0)
    {
        $this->x = $x;
        $this->y = $y;
        $this->width = max(0.0, $width);
        $this->height = max(0.0, $height);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->x . ',' . $this->y . ',' . $this->width . ',' . $this->height);
    }

    public function maxX(): float
    {
        return $this->x + $this->width;
    }

    public function maxY(): float
    {
        return $this->y + $this->height;
    }

    public function isEmpty(): bool
    {
        return $this->width === 0.0 || $this->height === 0.0;
    }

    /**
     * Returns the box as WKT `POLYGON((x y, mx y, mx my, x my, x y))` — closed clockwise
     * rectangle. The Doctrine upsert path feeds this directly into `ST_GeomFromText(?)`.
     * An empty box (zero width or height) returns an empty string — MySQL rejects degenerate
     * polygons.
     */
    public function __toString(): string
    {
        if ($this->isEmpty()) {
            return '';
        }
        $mx = $this->maxX();
        $my = $this->maxY();
        return sprintf(
            'POLYGON((%F %F, %F %F, %F %F, %F %F, %F %F))',
            $this->x, $this->y, $mx, $this->y, $mx, $my, $this->x, $my, $this->x, $this->y
        );
    }

    /**
     * Closed four-vertex polygon (clockwise from top-left). The closing vertex is implicit — the
     * Doctrine type emits a duplicate trailing vertex when serialising to `POLYGON` WKT.
     */
    public function toPolygon(): Polygon
    {
        return new Polygon([
            new Point2D($this->x, $this->y),
            new Point2D($this->maxX(), $this->y),
            new Point2D($this->maxX(), $this->maxY()),
            new Point2D($this->x, $this->maxY()),
        ]);
    }

    /**
     * Reconstructs a BoundingBox2D from a four- or five-vertex axis-aligned rectangle polygon.
     * Returns null when the polygon has holes, has the wrong vertex count, or is not axis-aligned.
     */
    public static function fromPolygon(Polygon $polygon): ?BoundingBox2D
    {
        if ($polygon->innerRings !== []) {
            return null;
        }
        $ring = $polygon->outerRing;
        // Accept both implicit-close (4 verts) and explicit-close (5 verts) forms.
        if (count($ring) === 5 && $ring[0]->x === $ring[4]->x && $ring[0]->y === $ring[4]->y) {
            $ring = array_slice($ring, 0, 4);
        }
        if (count($ring) !== 4) {
            return null;
        }
        $xs = array_map(static fn (Point2D $p) => $p->x, $ring);
        $ys = array_map(static fn (Point2D $p) => $p->y, $ring);
        $minX = min($xs);
        $maxX = max($xs);
        $minY = min($ys);
        $maxY = max($ys);
        // Verify it's axis-aligned: every vertex must sit on a corner of the min/max box.
        foreach ($ring as $point) {
            $onXEdge = $point->x === $minX || $point->x === $maxX;
            $onYEdge = $point->y === $minY || $point->y === $maxY;
            if (!$onXEdge || !$onYEdge) {
                return null;
            }
        }
        return new self($minX, $minY, $maxX - $minX, $maxY - $minY);
    }
}
