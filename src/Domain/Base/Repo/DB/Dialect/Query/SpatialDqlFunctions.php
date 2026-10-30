<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Brick\Geo\Doctrine\Functions\AreaFunction;
use Brick\Geo\Doctrine\Functions\AzimuthFunction;
use Brick\Geo\Doctrine\Functions\BoundaryFunction;
use Brick\Geo\Doctrine\Functions\BufferFunction;
use Brick\Geo\Doctrine\Functions\CentroidFunction;
use Brick\Geo\Doctrine\Functions\ContainsFunction;
use Brick\Geo\Doctrine\Functions\ConvexHullFunction;
use Brick\Geo\Doctrine\Functions\CrossesFunction;
use Brick\Geo\Doctrine\Functions\DifferenceFunction;
use Brick\Geo\Doctrine\Functions\DisjointFunction;
use Brick\Geo\Doctrine\Functions\DistanceFunction;
use Brick\Geo\Doctrine\Functions\EnvelopeFunction;
use Brick\Geo\Doctrine\Functions\EqualsFunction;
use Brick\Geo\Doctrine\Functions\IntersectsFunction;
use Brick\Geo\Doctrine\Functions\IsClosedFunction;
use Brick\Geo\Doctrine\Functions\IsSimpleFunction;
use Brick\Geo\Doctrine\Functions\IsValidFunction;
use Brick\Geo\Doctrine\Functions\LengthFunction;
use Brick\Geo\Doctrine\Functions\LocateAlongFunction;
use Brick\Geo\Doctrine\Functions\LocateBetweenFunction;
use Brick\Geo\Doctrine\Functions\MaxDistanceFunction;
use Brick\Geo\Doctrine\Functions\OverlapsFunction;
use Brick\Geo\Doctrine\Functions\PointOnSurfaceFunction;
use Brick\Geo\Doctrine\Functions\RelateFunction;
use Brick\Geo\Doctrine\Functions\SimplifyFunction;
use Brick\Geo\Doctrine\Functions\SnapToGridFunction;
use Brick\Geo\Doctrine\Functions\SymDifferenceFunction;
use Brick\Geo\Doctrine\Functions\TouchesFunction;
use Brick\Geo\Doctrine\Functions\UnionFunction;
use Brick\Geo\Doctrine\Functions\WithinFunction;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StAsGeoJSON;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StAsText;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StEndPoint;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StGeomFromGeoJSON;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StGeomFromText;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StGeometryType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StIntersection;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StLineFromText;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StNumPoints;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StPointFromText;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StPolyFromText;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StSrid;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StStartPoint;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StTransform;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StX;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StY;
use Doctrine\ORM\Configuration;

/**
 * The spatial DQL functions both dialects register (`ST_GeomFromText`, `ST_AsText`, `ST_X`, `ST_Within`, …). Each renders
 * `<name>(<arguments>)`, which MariaDB and PostGIS both understand under the same name; geometry values are written
 * with SRID 0 on both (the Doctrine spatial types and the `geometry(…, 0)` columns). `ST_Distance_Sphere` differs per
 * dialect and is registered by each dialect itself.
 */
class SpatialDqlFunctions
{
    public static function register(Configuration $configuration): void
    {
        // ST_GeoLength renders ST_Length — the DQL name avoids Doctrine's built-in LENGTH
        $configuration->addCustomStringFunction('ST_Envelope', EnvelopeFunction::class);
        $configuration->addCustomStringFunction('ST_Boundary', BoundaryFunction::class);
        $configuration->addCustomStringFunction('ST_Buffer', BufferFunction::class);
        $configuration->addCustomStringFunction('ST_Centroid', CentroidFunction::class);
        $configuration->addCustomStringFunction('ST_ConvexHull', ConvexHullFunction::class);
        $configuration->addCustomStringFunction('ST_Difference', DifferenceFunction::class);
        $configuration->addCustomStringFunction('ST_PointOnSurface', PointOnSurfaceFunction::class);
        $configuration->addCustomStringFunction('ST_Simplify', SimplifyFunction::class);
        $configuration->addCustomStringFunction('ST_SnapToGrid', SnapToGridFunction::class);
        $configuration->addCustomStringFunction('ST_SymDifference', SymDifferenceFunction::class);
        $configuration->addCustomStringFunction('ST_Union', UnionFunction::class);
        $configuration->addCustomStringFunction('ST_GeomFromText', StGeomFromText::class);
        $configuration->addCustomStringFunction('ST_PointFromText', StPointFromText::class);
        $configuration->addCustomStringFunction('ST_LineFromText', StLineFromText::class);
        $configuration->addCustomStringFunction('ST_PolyFromText', StPolyFromText::class);
        $configuration->addCustomStringFunction('ST_AsText', StAsText::class);
        $configuration->addCustomStringFunction('ST_GeomFromGeoJSON', StGeomFromGeoJSON::class);
        $configuration->addCustomStringFunction('ST_AsGeoJSON', StAsGeoJSON::class);
        $configuration->addCustomStringFunction('ST_GeometryType', StGeometryType::class);
        $configuration->addCustomStringFunction('ST_StartPoint', StStartPoint::class);
        $configuration->addCustomStringFunction('ST_EndPoint', StEndPoint::class);
        $configuration->addCustomStringFunction('ST_Intersection', StIntersection::class);
        $configuration->addCustomStringFunction('ST_Transform', StTransform::class);
        $configuration->addCustomNumericFunction('ST_Area', AreaFunction::class);
        $configuration->addCustomNumericFunction('ST_Within', WithinFunction::class);
        $configuration->addCustomNumericFunction('ST_Contains', ContainsFunction::class);
        $configuration->addCustomNumericFunction('ST_Azimuth', AzimuthFunction::class);
        $configuration->addCustomNumericFunction('ST_Crosses', CrossesFunction::class);
        $configuration->addCustomNumericFunction('ST_Disjoint', DisjointFunction::class);
        $configuration->addCustomNumericFunction('ST_Distance', DistanceFunction::class);
        $configuration->addCustomNumericFunction('ST_Equals', EqualsFunction::class);
        $configuration->addCustomNumericFunction('ST_Intersects', IntersectsFunction::class);
        $configuration->addCustomNumericFunction('ST_IsClosed', IsClosedFunction::class);
        $configuration->addCustomNumericFunction('ST_IsSimple', IsSimpleFunction::class);
        $configuration->addCustomNumericFunction('ST_IsValid', IsValidFunction::class);
        $configuration->addCustomNumericFunction('ST_GeoLength', LengthFunction::class);
        $configuration->addCustomNumericFunction('ST_LocateAlong', LocateAlongFunction::class);
        $configuration->addCustomNumericFunction('ST_LocateBetween', LocateBetweenFunction::class);
        $configuration->addCustomNumericFunction('ST_MaxDistance', MaxDistanceFunction::class);
        $configuration->addCustomNumericFunction('ST_Overlaps', OverlapsFunction::class);
        $configuration->addCustomNumericFunction('ST_Relate', RelateFunction::class);
        $configuration->addCustomNumericFunction('ST_Touches', TouchesFunction::class);
        $configuration->addCustomNumericFunction('ST_SRID', StSrid::class);
        $configuration->addCustomNumericFunction('ST_X', StX::class);
        $configuration->addCustomNumericFunction('ST_Y', StY::class);
        $configuration->addCustomNumericFunction('ST_NumPoints', StNumPoints::class);
    }
}
