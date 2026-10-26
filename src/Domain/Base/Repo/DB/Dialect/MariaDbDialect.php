<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

// Brick\Geo Doctrine Function imports (vendor)
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

// Brick\Geo Doctrine Type imports (vendor) — aliased so the identically-named DDD types below keep their short names.
use Brick\Geo\Doctrine\Types\GeometryType;
use Brick\Geo\Doctrine\Types\LineStringType as BrickLineStringType;
use Brick\Geo\Doctrine\Types\MultiLineStringType;
use Brick\Geo\Doctrine\Types\MultiPointType;
use Brick\Geo\Doctrine\Types\MultiPolygonType;
use Brick\Geo\Doctrine\Types\PointType as BrickPointType;

use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\CosineDistance;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\CosineSimilarity;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Distance;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\EuclideanDistance;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StAsGeoJSON;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StAsText;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StDistanceSphere;
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
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\VecFromText;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\BoundingBoxType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\LineStringType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\PointType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\PolygonType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\UnescapedJsonType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\VectorType;

use DDD\Domain\Base\Repo\DB\Dialect\Query\MariaDbDistinctSubqueryPagination;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PaginationStrategy;
use DDD\Domain\Base\Repo\DB\Dialect\Locks\LockService;
use DDD\Domain\Base\Repo\DB\Dialect\Locks\MariaDbLockService;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlRenderer;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\MariaDbDdlRenderer;
use DDD\Domain\Base\Repo\DB\Dialect\Write\MariaDbUpsertBuilder;
use DDD\Domain\Base\Repo\DB\Dialect\Write\RawUpsertExecutor;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertBuilder;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertResult;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertSpec;
use Doctrine\DBAL\Connection;

use DoctrineExtensions\Query\Mysql\DateAdd;
use DoctrineExtensions\Query\Mysql\Field;
use DoctrineExtensions\Query\Mysql\IfNull;
use DoctrineExtensions\Query\Mysql\MatchAgainst;
use DoctrineExtensions\Query\Mysql\Rand;
use DoctrineExtensions\Query\Mysql\StrToDate;
use DoctrineExtensions\Types\PolygonType as BrickPolygonType;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;

/**
 * MariaDB dialect: §6 build step 2 is a **pure move** — the DQL function and type registrations that used to sit
 * inline in {@see \DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory::create()} are relocated here verbatim,
 * byte-for-byte, so the T0 golden stays identical. No new behaviour; `connectionParams()`/`quote()` mirror today's
 * (absence of) handling.
 */
class MariaDbDialect implements SqlDialect
{
    public function name(): string
    {
        return self::MARIADB;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(self::MARIADB);
    }

    public function configureOrm(Configuration $configuration): void
    {
        // ─── DoctrineExtensions: general-purpose MySQL functions ───
        $configuration->addCustomStringFunction('FIELD', Field::class);
        $configuration->addCustomStringFunction('IFNULL', IfNull::class);
        $configuration->addCustomStringFunction('MATCH', MatchAgainst::class);
        $configuration->addCustomStringFunction('DATE_ADD', DateAdd::class);
        $configuration->addCustomStringFunction('STR_TO_DATE', StrToDate::class);
        $configuration->addCustomNumericFunction('RAND', Rand::class);

        // ─── Spatial functions returning geometry/string (addCustomStringFunction) ───
        // From brick/geo-doctrine vendor
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

        // From custom Geo functions
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

        // ─── Spatial functions returning numeric/boolean (addCustomNumericFunction) ───
        // From brick/geo-doctrine vendor
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
        // Use ST_GeoLength to avoid conflict with Doctrine's built-in LENGTH function
        $configuration->addCustomNumericFunction('ST_GeoLength', LengthFunction::class);
        $configuration->addCustomNumericFunction('ST_LocateAlong', LocateAlongFunction::class);
        $configuration->addCustomNumericFunction('ST_LocateBetween', LocateBetweenFunction::class);
        $configuration->addCustomNumericFunction('ST_MaxDistance', MaxDistanceFunction::class);
        $configuration->addCustomNumericFunction('ST_Overlaps', OverlapsFunction::class);
        $configuration->addCustomNumericFunction('ST_Relate', RelateFunction::class);
        $configuration->addCustomNumericFunction('ST_Touches', TouchesFunction::class);

        // From custom Geo functions
        $configuration->addCustomNumericFunction('ST_Distance_Sphere', StDistanceSphere::class);
        $configuration->addCustomNumericFunction('ST_SRID', StSrid::class);
        $configuration->addCustomNumericFunction('ST_X', StX::class);
        $configuration->addCustomNumericFunction('ST_Y', StY::class);
        $configuration->addCustomNumericFunction('ST_NumPoints', StNumPoints::class);

        // ─── JSON type override: preserve Unicode characters (e.g. "Münster" instead of "Münster") ───
        Type::overrideType('json', UnescapedJsonType::class);

        // ─── Vector functions ───
        if (!Type::hasType(VectorType::NAME)) {
            Type::addType(VectorType::NAME, VectorType::class);
        }

        $configuration->addCustomStringFunction('VEC_FROM_TEXT', VecFromText::class);
        $configuration->addCustomNumericFunction('COSINE_DISTANCE', CosineDistance::class);
        $configuration->addCustomNumericFunction('EUCLIDEAN_DISTANCE', EuclideanDistance::class);
        $configuration->addCustomNumericFunction('VEC_DISTANCE', Distance::class);
        $configuration->addCustomNumericFunction('COSINE_SIMILARITY', CosineSimilarity::class);

        // ─── Spatial column types ───
        if (!Type::hasType('point')) {
            Type::addType('point', BrickPointType::class);
        }
        if (!Type::hasType('geometry')) {
            Type::addType('geometry', GeometryType::class);
        }
        if (!Type::hasType('linestring')) {
            Type::addType('linestring', BrickLineStringType::class);
        }
        if (!Type::hasType('polygon')) {
            Type::addType('polygon', BrickPolygonType::class);
        }
        if (!Type::hasType('multilinestring')) {
            Type::addType('multilinestring', MultiLineStringType::class);
        }
        if (!Type::hasType('multipoint')) {
            Type::addType('multipoint', MultiPointType::class);
        }
        if (!Type::hasType('multipolygon')) {
            Type::addType('multipolygon', MultiPolygonType::class);
        }

        // ─── Cartesian geometry types (SRID 0) — Point2D / Polyline / Polygon / BoundingBox2D ───
        // Distinct from the brick/geo types above (which carry SRID 4326 / Brick\Geo\* PHP types).
        // These map directly to the DDD\Domain\Common\Entities\Geometry\Cartesian\* VOs and live
        // alongside `point` / `linestring` / `polygon` as separate registered type names.
        if (!Type::hasType(PointType::NAME)) {
            Type::addType(PointType::NAME, PointType::class);
        }
        if (!Type::hasType(LineStringType::NAME)) {
            Type::addType(LineStringType::NAME, LineStringType::class);
        }
        if (!Type::hasType(PolygonType::NAME)) {
            Type::addType(PolygonType::NAME, PolygonType::class);
        }
        if (!Type::hasType(BoundingBoxType::NAME)) {
            Type::addType(BoundingBoxType::NAME, BoundingBoxType::class);
        }
    }

    public function connectionParams(array $params): array
    {
        // Pure move (§6 step 2): EntityManagerFactory::create() applied no transformation to MariaDB connection
        // params before this refactor; keeping that exactly is what makes the golden suite byte-identical.
        return $params;
    }

    public function quote(string $identifier): string
    {
        return '`' . $identifier . '`';
    }

    public function ddlRenderer(): DdlRenderer
    {
        return new MariaDbDdlRenderer();
    }

    public function upsertBuilder(): UpsertBuilder
    {
        return new MariaDbUpsertBuilder();
    }

    /** §6 step 5: today's regex-based distinct-subquery rewrite, unchanged (golden-sensitive behaviour, not SQL text). */
    public function pagination(): PaginationStrategy
    {
        return new MariaDbDistinctSubqueryPagination();
    }

    public function locks(Connection $connection, string $scope = 'DEFAULT'): LockService
    {
        return new MariaDbLockService($connection);
    }

    // ─── §4.3 app-facing SQL-fragment helpers — every rendering below is exactly what the app already writes
    // (verified against the raw SQL call sites the dual-dialect migration plan's §4.13 sweep touched), unchanged
    // by this build step; only PostgresDialect's renderings are new.

    public function now(): string
    {
        return 'NOW()';
    }

    public function intervalAgo(string $amountSql, string $unit): string
    {
        return "DATE_SUB(NOW(), INTERVAL $amountSql $unit)";
    }

    public function dateAdd(string $columnSql, string $amountSql, string $unit): string
    {
        return "DATE_ADD($columnSql, INTERVAL $amountSql $unit)";
    }

    public function secondsBetween(string $fromSql, string $toSql): string
    {
        return "TIMESTAMPDIFF(SECOND, $fromSql, $toSql)";
    }

    public function dateBucket(string $columnSql, string $bucket): string
    {
        return match ($bucket) {
            'week' => "DATE_FORMAT(DATE_SUB($columnSql, INTERVAL WEEKDAY($columnSql) DAY), '%Y-%m-%d')",
            'month' => "DATE_FORMAT($columnSql, '%Y-%m-01')",
            default => "DATE_FORMAT($columnSql, '%Y-%m-%d')",
        };
    }

    public function stringAgg(string $exprSql, string $separator, string $orderBySql): string
    {
        $quotedSeparator = "'" . str_replace("'", "''", $separator) . "'";
        return "GROUP_CONCAT($exprSql ORDER BY $orderBySql SEPARATOR $quotedSeparator)";
    }

    public function currentDatabase(): string
    {
        return 'DATABASE()';
    }

    public function rowHash60(array $columnSqls): string
    {
        return 'SUBSTRING(SHA2(CONCAT_WS(\'|\', ' . implode(', ', $columnSqls) . '), 256), 1, 60)';
    }

    public function updateFromJoin(UpdateFromJoinSpec $spec): string
    {
        $assignments = [];
        foreach ($spec->setAssignments as $columnName => $expressionSql) {
            $assignments[] = $spec->targetAlias . '.`' . $columnName . '` = ' . $expressionSql;
        }
        $sql = 'UPDATE `' . $spec->targetTable . '` AS ' . $spec->targetAlias
            . ' JOIN `' . $spec->joinTable . '` AS ' . $spec->joinAlias . ' ON ' . $spec->joinOnSql
            . ' SET ' . implode(', ', $assignments);
        if ($spec->whereSql !== null) {
            $sql .= ' WHERE ' . $spec->whereSql;
        }
        return $sql;
    }

    public function jsonFieldText(string $columnSql, string $key): string
    {
        return "JSON_UNQUOTE(JSON_EXTRACT($columnSql, '$.\"$key\"'))";
    }

    public function bytesEqual(string $leftSql, string $rightSql): string
    {
        return "$leftSql <=> $rightSql";
    }

    public function boolLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    public function upsert(Connection $connection, UpsertSpec $spec): UpsertResult
    {
        return RawUpsertExecutor::upsertMariaDb($connection, $spec);
    }

    public function insertOrNull(Connection $connection, UpsertSpec $spec): ?int
    {
        return RawUpsertExecutor::insertOrNullMariaDb($connection, $spec);
    }
}
