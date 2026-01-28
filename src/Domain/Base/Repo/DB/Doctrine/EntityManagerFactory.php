<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine;

use Brick\Geo\Doctrine\Functions\AreaFunction;
use Brick\Geo\Doctrine\Functions\ContainsFunction;
use Brick\Geo\Doctrine\Functions\EnvelopeFunction;
use Brick\Geo\Doctrine\Functions\WithinFunction;
use Brick\Geo\Doctrine\Types\GeometryType;
use Brick\Geo\Doctrine\Types\LineStringType;
use Brick\Geo\Doctrine\Types\MultiLineStringType;
use Brick\Geo\Doctrine\Types\MultiPointType;
use Brick\Geo\Doctrine\Types\MultiPolygonType;
use Brick\Geo\Doctrine\Types\PointType;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\Common\Proxy\AbstractProxyFactory;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\ORMSetup;
use DoctrineExtensions\Query\Mysql\DateAdd;
use DoctrineExtensions\Query\Mysql\Field;
use DoctrineExtensions\Query\Mysql\IfNull;
use DoctrineExtensions\Query\Mysql\MatchAgainst;
use DoctrineExtensions\Query\Mysql\Rand;
use DoctrineExtensions\Query\Mysql\StrToDate;
use DoctrineExtensions\Types\PolygonType;
use RuntimeException;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\CosineDistance;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\CosineSimilarity;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Distance;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\EuclideanDistance;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\VecFromText;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\VectorType;

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
        $config->addCustomStringFunction('FIELD', Field::class);
        $config->addCustomStringFunction('IFNULL', IfNull::class);
        $config->addCustomStringFunction('MATCH', MatchAgainst::class);
        $config->addCustomNumericFunction('RAND', Rand::class);
        $config->addCustomStringFunction('FIELD', Field::class);
        $config->addCustomStringFunction('IFNULL', IfNull::class);
        $config->addCustomStringFunction('MATCH', MatchAgainst::class);
        $config->addCustomStringFunction('DATE_ADD', DateAdd::class);
        $config->addCustomNumericFunction('RAND', Rand::class);
        $config->addCustomStringFunction('STR_TO_DATE', StrToDate::class);
        // This is an example to declare a standard spatial function which is returning a string
        $config->addCustomStringFunction('ST_Envelope', EnvelopeFunction::class);
        $config->addCustomNumericFunction('ST_Area', AreaFunction::class);
        $config->addCustomNumericFunction('ST_Within', WithinFunction::class);
        $config->addCustomNumericFunction('ST_Contains', ContainsFunction::class);

        $serverVersion = $configSettings['connectionParams']['server_version'] ?? null;
        if (!Type::hasType(VectorType::NAME)) {
            Type::addType(VectorType::NAME, VectorType::class);
        }

        $config->addCustomStringFunction('VEC_FROM_TEXT', VecFromText::class);
        $config->addCustomNumericFunction('COSINE_DISTANCE', CosineDistance::class);
        $config->addCustomNumericFunction('EUCLIDEAN_DISTANCE', EuclideanDistance::class);
        $config->addCustomNumericFunction('VEC_DISTANCE', Distance::class);
        $config->addCustomNumericFunction('COSINE_SIMILARITY', CosineSimilarity::class);

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


        $config->setProxyNamespace(self::DEFAULT_NAMESPACE);
        $config->setAutoGenerateProxyClasses($configSettings['doctrineConfig']['proxyGenerationMode']);

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
