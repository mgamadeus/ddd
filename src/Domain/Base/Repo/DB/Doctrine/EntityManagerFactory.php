<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine;

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

// Brick\Geo Doctrine Type imports (vendor)
use Brick\Geo\Doctrine\Types\GeometryType;
use Brick\Geo\Doctrine\Types\LineStringType;
use Brick\Geo\Doctrine\Types\MultiLineStringType;
use Brick\Geo\Doctrine\Types\MultiPointType;
use Brick\Geo\Doctrine\Types\MultiPolygonType;
use Brick\Geo\Doctrine\Types\PointType;

// Infrastructure / Framework imports
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\Common\Proxy\AbstractProxyFactory;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\ORMSetup;

// DoctrineExtensions imports
use DoctrineExtensions\Query\Mysql\DateAdd;
use DoctrineExtensions\Query\Mysql\Field;
use DoctrineExtensions\Query\Mysql\IfNull;
use DoctrineExtensions\Query\Mysql\MatchAgainst;
use DoctrineExtensions\Query\Mysql\Rand;
use DoctrineExtensions\Query\Mysql\StrToDate;
use DoctrineExtensions\Types\PolygonType;

use RuntimeException;

// Custom Vector Query functions
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\CosineDistance;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\CosineSimilarity;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Distance;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\EuclideanDistance;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\VecFromText;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\VectorType;

// Custom Geo/Spatial Query functions
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

class EntityManagerFactory
{
    /** @var DoctrineEntityManager[] */
    private static array $instances = [];

    public const string SCOPE_DEFAULT = 'DEFAULT';

    public const string DEFAULT_NAMESPACE = 'DoctrineProxies';

    public const string SCOPE_LEGACY_DB = 'LEGACY_DB';

    public static function getInstance(
        string $scope = self::SCOPE_DEFAULT
    ): DoctrineEntityManager {
        if (!isset(self::$instances[$scope])) {
            self::create($scope);
        }
        if (!self::$instances[$scope]->isOpen()) {
            self::create($scope);
        }
        // Check if the connection is still pingable
        if (!self::$instances[$scope]->isConnectionActive()) {
            self::create($scope);
        }

        return self::$instances[$scope];
    }

    /**
     * @return void Iterates through all instances and calls clear(), this clears Doctrine unit of work caches
     */
    public static function clearAllInstanceCaches(): void
    {
        foreach (self::$instances as $instance) {
            $instance->clear();
        }
        gc_collect_cycles();
    }

    protected static function getDBConfigForScope(string $scope = self::SCOPE_DEFAULT): array
    {
        $config = [
            'driver' => Config::getEnv("DB_{$scope}_CONNECTION_DRIVER"),
            'host' => Config::getEnv("DB_{$scope}_CONNECTION_HOST"),
            'user' => Config::getEnv("DB_{$scope}_CONNECTION_USER"),
            'password' => Config::getEnv("DB_{$scope}_CONNECTION_PASSWORD"),
            'dbname' => Config::getEnv("DB_{$scope}_CONNECTION_DB_NAME"),
            'server_version' => Config::getEnv("DB_{$scope}_CONNECTION_DB_SERVER_VERSION"),
            'charset' => Config::getEnv("DB_{$scope}_CONNECTION_DB_SERVER_CHARSET"),
        ];
        if (Config::getEnv("DB_{$scope}_CONNECTION_HOST_PORT")) {
            $config ['port'] = Config::getEnv("DB_{$scope}_CONNECTION_HOST_PORT");
        }
        $proxyGenerationMode = AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS;
        if ($proxyGenerationConfig = Config::getEnv("DB_{$scope}_DOCTRINE_PROXY_GENERATION_MODE")) {
            $proxyGenerationMode = match ($proxyGenerationConfig) {
                'AUTOGENERATE_FILE_NOT_EXISTS' => AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS,
                'AUTOGENERATE_ALWAYS' => AbstractProxyFactory::AUTOGENERATE_ALWAYS,
                'AUTOGENERATE_NEVER' => AbstractProxyFactory::AUTOGENERATE_NEVER,
                'AUTOGENERATE_EVAL' => AbstractProxyFactory::AUTOGENERATE_EVAL,
                'AUTOGENERATE_FILE_NOT_EXISTS_OR_CHANGED' => AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS_OR_CHANGED
            };
        }
        return ['connectionParams' => $config, 'doctrineConfig' => ['proxyGenerationMode' => $proxyGenerationMode]];
    }

    public static function create(string $scope = self::SCOPE_DEFAULT)
    {
        $isDevMode = false;
        // Specify the directory where Doctrine should save proxy classes
        $proxyDir = DDDService::instance()->getCacheDir(false) . '/doctrine/orm/Proxies';
        if (!is_dir($proxyDir)) {
            // Attempt to create the directory with recursive flag set to true
            if (!mkdir($proxyDir, 0775, true)) {
                throw new RuntimeException(sprintf('Could not create proxy directory "%s".', $proxyDir));
            }
        }
        $cache = Cache::instance('DOCTRINE')->getCacheAdapter();
        $metaDataCache = Cache::instance('DOCTRINE_METADATA')->getCacheAdapter();
        $configSettings = self::getDBConfigForScope($scope);

        $config = ORMSetup::createAttributeMetadataConfiguration(
            [__DIR__ . '/../Domain'], // Adjusted path for correctness
            $isDevMode,
            $proxyDir, // Now passing the specified proxy directory
            $cache
        );
        $config->setMetadataCache($metaDataCache);
        $config->setClassMetadataFactoryName(ClassMetadataFactory::class);

        // ─── DoctrineExtensions: general-purpose MySQL functions ───
        $config->addCustomStringFunction('FIELD', Field::class);
        $config->addCustomStringFunction('IFNULL', IfNull::class);
        $config->addCustomStringFunction('MATCH', MatchAgainst::class);
        $config->addCustomStringFunction('DATE_ADD', DateAdd::class);
        $config->addCustomStringFunction('STR_TO_DATE', StrToDate::class);
        $config->addCustomNumericFunction('RAND', Rand::class);

        // ─── Spatial functions returning geometry/string (addCustomStringFunction) ───
        // From brick/geo-doctrine vendor
        $config->addCustomStringFunction('ST_Envelope', EnvelopeFunction::class);
        $config->addCustomStringFunction('ST_Boundary', BoundaryFunction::class);
        $config->addCustomStringFunction('ST_Buffer', BufferFunction::class);
        $config->addCustomStringFunction('ST_Centroid', CentroidFunction::class);
        $config->addCustomStringFunction('ST_ConvexHull', ConvexHullFunction::class);
        $config->addCustomStringFunction('ST_Difference', DifferenceFunction::class);
        $config->addCustomStringFunction('ST_PointOnSurface', PointOnSurfaceFunction::class);
        $config->addCustomStringFunction('ST_Simplify', SimplifyFunction::class);
        $config->addCustomStringFunction('ST_SnapToGrid', SnapToGridFunction::class);
        $config->addCustomStringFunction('ST_SymDifference', SymDifferenceFunction::class);
        $config->addCustomStringFunction('ST_Union', UnionFunction::class);

        // From custom Geo functions
        $config->addCustomStringFunction('ST_GeomFromText', StGeomFromText::class);
        $config->addCustomStringFunction('ST_PointFromText', StPointFromText::class);
        $config->addCustomStringFunction('ST_LineFromText', StLineFromText::class);
        $config->addCustomStringFunction('ST_PolyFromText', StPolyFromText::class);
        $config->addCustomStringFunction('ST_AsText', StAsText::class);
        $config->addCustomStringFunction('ST_GeomFromGeoJSON', StGeomFromGeoJSON::class);
        $config->addCustomStringFunction('ST_AsGeoJSON', StAsGeoJSON::class);
        $config->addCustomStringFunction('ST_GeometryType', StGeometryType::class);
        $config->addCustomStringFunction('ST_StartPoint', StStartPoint::class);
        $config->addCustomStringFunction('ST_EndPoint', StEndPoint::class);
        $config->addCustomStringFunction('ST_Intersection', StIntersection::class);
        $config->addCustomStringFunction('ST_Transform', StTransform::class);

        // ─── Spatial functions returning numeric/boolean (addCustomNumericFunction) ───
        // From brick/geo-doctrine vendor
        $config->addCustomNumericFunction('ST_Area', AreaFunction::class);
        $config->addCustomNumericFunction('ST_Within', WithinFunction::class);
        $config->addCustomNumericFunction('ST_Contains', ContainsFunction::class);
        $config->addCustomNumericFunction('ST_Azimuth', AzimuthFunction::class);
        $config->addCustomNumericFunction('ST_Crosses', CrossesFunction::class);
        $config->addCustomNumericFunction('ST_Disjoint', DisjointFunction::class);
        $config->addCustomNumericFunction('ST_Distance', DistanceFunction::class);
        $config->addCustomNumericFunction('ST_Equals', EqualsFunction::class);
        $config->addCustomNumericFunction('ST_Intersects', IntersectsFunction::class);
        $config->addCustomNumericFunction('ST_IsClosed', IsClosedFunction::class);
        $config->addCustomNumericFunction('ST_IsSimple', IsSimpleFunction::class);
        $config->addCustomNumericFunction('ST_IsValid', IsValidFunction::class);
        // Use ST_GeoLength to avoid conflict with Doctrine's built-in LENGTH function
        $config->addCustomNumericFunction('ST_GeoLength', LengthFunction::class);
        $config->addCustomNumericFunction('ST_LocateAlong', LocateAlongFunction::class);
        $config->addCustomNumericFunction('ST_LocateBetween', LocateBetweenFunction::class);
        $config->addCustomNumericFunction('ST_MaxDistance', MaxDistanceFunction::class);
        $config->addCustomNumericFunction('ST_Overlaps', OverlapsFunction::class);
        $config->addCustomNumericFunction('ST_Relate', RelateFunction::class);
        $config->addCustomNumericFunction('ST_Touches', TouchesFunction::class);

        // From custom Geo functions
        $config->addCustomNumericFunction('ST_Distance_Sphere', StDistanceSphere::class);
        $config->addCustomNumericFunction('ST_SRID', StSrid::class);
        $config->addCustomNumericFunction('ST_X', StX::class);
        $config->addCustomNumericFunction('ST_Y', StY::class);
        $config->addCustomNumericFunction('ST_NumPoints', StNumPoints::class);

        // ─── Vector functions ───
        $serverVersion = $configSettings['connectionParams']['server_version'] ?? null;
        if (!Type::hasType(VectorType::NAME)) {
            Type::addType(VectorType::NAME, VectorType::class);
        }

        $config->addCustomStringFunction('VEC_FROM_TEXT', VecFromText::class);
        $config->addCustomNumericFunction('COSINE_DISTANCE', CosineDistance::class);
        $config->addCustomNumericFunction('EUCLIDEAN_DISTANCE', EuclideanDistance::class);
        $config->addCustomNumericFunction('VEC_DISTANCE', Distance::class);
        $config->addCustomNumericFunction('COSINE_SIMILARITY', CosineSimilarity::class);

        // ─── Spatial column types ───
        $config->setProxyNamespace(self::DEFAULT_NAMESPACE);
        $config->setAutoGenerateProxyClasses($configSettings['doctrineConfig']['proxyGenerationMode']);
        if (!Type::hasType('point')) {
            Type::addType('point', PointType::class);
        }
        if (!Type::hasType('geometry')) {
            Type::addType('geometry', GeometryType::class);
        }
        if (!Type::hasType('linestring')) {
            Type::addType('linestring', LineStringType::class);
        }
        if (!Type::hasType('polygon')) {
            Type::addType('polygon', PolygonType::class);
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

        self::$instances[$scope] = DoctrineEntityManager::create($configSettings['connectionParams'], $config);
    }

    /**
     * @return DoctrineQueryBuilder
     */
    public static function createQueryBuilder(string $scope = self::SCOPE_DEFAULT): DoctrineQueryBuilder
    {
        return self::getInstance(scope: $scope)->createQueryBuilder();
    }
}
