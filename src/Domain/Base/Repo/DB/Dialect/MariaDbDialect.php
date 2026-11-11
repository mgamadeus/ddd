<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

// Brick\Geo Doctrine Function imports (vendor)

// Brick\Geo Doctrine Type imports (vendor) — aliased so the identically-named DDD types below keep their short names.
use Brick\Geo\Doctrine\Types\GeometryType;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Brick\Geo\Doctrine\Types\LineStringType as BrickLineStringType;
use Brick\Geo\Doctrine\Types\MultiLineStringType;
use Brick\Geo\Doctrine\Types\MultiPointType;
use Brick\Geo\Doctrine\Types\MultiPolygonType;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonDocumentUpdate;
use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Dialect\Json\MariaDbJsonDocumentUpdate;
use Brick\Geo\Doctrine\Types\PointType as BrickPointType;

use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\CosineDistance;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\CosineSimilarity;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Distance;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\EuclideanDistance;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\Geo\StDistanceSphere;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Query\VecFromText;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\BoundingBoxType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\LineStringType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\PointType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\PolygonType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\UnescapedJsonType;
use DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types\VectorType;

use DDD\Domain\Base\Repo\DB\Dialect\Query\MariaDbDistinctSubqueryPagination;
use DDD\Domain\Base\Repo\DB\Dialect\Query\OctetLength;
use DDD\Domain\Base\Repo\DB\Dialect\Query\PaginationStrategy;
use DDD\Domain\Base\Repo\DB\Dialect\Query\SpatialDqlFunctions;
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
use Doctrine\DBAL\TransactionIsolationLevel;

use DoctrineExtensions\Query\Mysql\DateAdd;
use DoctrineExtensions\Query\Mysql\Field;
use DoctrineExtensions\Query\Mysql\IfNull;
use DoctrineExtensions\Query\Mysql\MatchAgainst;
use DoctrineExtensions\Query\Mysql\Rand;
use DoctrineExtensions\Query\Mysql\StrToDate;
use DoctrineExtensions\Types\PolygonType as BrickPolygonType;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use WeakMap;

/**
 * MariaDB dialect: §6 build step 2 is a **pure move** — the DQL function and type registrations that used to sit
 * inline in {@see \DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory::create()} are relocated here verbatim,
 * byte-for-byte, so the T0 golden stays identical. No new behaviour; `connectionParams()`/`quote()` mirror today's
 * (absence of) handling.
 */
class MariaDbDialect implements SqlDialect
{
    /** @var WeakMap<Connection, MariaDbLockService>|null The lock service of each connection, see {@see locks()} */
    protected ?WeakMap $lockServicesByConnection = null;

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
        $configuration->addCustomNumericFunction('OCTET_LENGTH', OctetLength::class);

        // ─── Spatial functions (shared with PostgreSQL; ST_Distance_Sphere is MariaDB's own) ───
        SpatialDqlFunctions::register($configuration);
        $configuration->addCustomNumericFunction('ST_Distance_Sphere', StDistanceSphere::class);

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

    /**
     * One {@see MariaDbLockService} per connection: `GET_LOCK` belongs to the session, and the depth map of
     * {@see \DDD\Domain\Base\Repo\DB\Dialect\Locks\AbstractLockService} only sees re-entrant and released names when every `locks()` call for that
     * connection gets the same instance. `$scope` is not needed: the connection is the scope's.
     */
    public function locks(Connection $connection, string $scope = 'DEFAULT'): LockService
    {
        $this->lockServicesByConnection ??= new WeakMap();
        return $this->lockServicesByConnection[$connection] ??= new MariaDbLockService($connection);
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

    public function stringAggDistinct(string $exprSql, string $separator): string
    {
        $quotedSeparator = "'" . str_replace("'", "''", $separator) . "'";
        return "GROUP_CONCAT(DISTINCT $exprSql ORDER BY $exprSql SEPARATOR $quotedSeparator)";
    }

    public function byteLength(string $exprSql): string
    {
        return "OCTET_LENGTH($exprSql)";
    }

    public function forShare(): string
    {
        return 'LOCK IN SHARE MODE';
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

    public function jsonText(string $columnSql, JsonPath $path): string
    {
        $valueSql = "JSON_EXTRACT($columnSql, {$path->toMariaDbPathSql()})";
        // JSON_UNQUOTE() alone answers the text 'null' for JSON null; JSON_VALUE() answers 1 for true
        return "IF(JSON_TYPE($valueSql) = 'NULL', NULL, JSON_UNQUOTE($valueSql))";
    }

    public function jsonInt(string $columnSql, JsonPath $path): string
    {
        return 'CAST(' . $this->jsonScalarValue($columnSql, $path) . ' AS SIGNED)';
    }

    public function jsonDecimal(string $columnSql, JsonPath $path, int $precision, int $scale): string
    {
        return 'CAST(' . $this->jsonScalarValue($columnSql, $path) . " AS DECIMAL($precision, $scale))";
    }

    /**
     * The scalar at a path as text, the document parsed once: `JSON_VALUE` answers NULL for a missing path, for JSON
     * null and for an object or array. Number reads only — it answers 1 for true, so {@see jsonText()} keeps its form.
     */
    protected function jsonScalarValue(string $columnSql, JsonPath $path): string
    {
        return "JSON_VALUE($columnSql, {$path->toMariaDbPathSql()})";
    }

    public function jsonBool(string $columnSql, JsonPath $path): string
    {
        return '(' . $this->jsonText($columnSql, $path) . " = 'true')";
    }

    public function jsonType(string $columnSql, JsonPath $path): string
    {
        return "CASE JSON_TYPE(JSON_EXTRACT($columnSql, {$path->toMariaDbPathSql()}))"
            . " WHEN 'OBJECT' THEN 'object' WHEN 'ARRAY' THEN 'array' WHEN 'STRING' THEN 'string'"
            . " WHEN 'BOOLEAN' THEN 'boolean' WHEN 'NULL' THEN 'null'"
            . " WHEN 'INTEGER' THEN 'number' WHEN 'DOUBLE' THEN 'number' WHEN 'DECIMAL' THEN 'number' END";
    }

    public function jsonLength(string $columnSql, JsonPath $path): string
    {
        return "JSON_LENGTH(JSON_EXTRACT($columnSql, {$path->toMariaDbPathSql()}))";
    }

    public function jsonUpdate(string $columnSql): JsonDocumentUpdate
    {
        return new MariaDbJsonDocumentUpdate($columnSql);
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

    public function insertSkippingExistingRows(string $insertSql, string $tableName, string $columnName): string
    {
        // Qualified with the table: an INSERT … SELECT from a table with the same column name is ambiguous otherwise
        $quotedColumn = $this->quote($tableName) . '.' . $this->quote($columnName);
        return rtrim($insertSql, " \t\n\r;") . " ON DUPLICATE KEY UPDATE $quotedColumn = $quotedColumn";
    }

    public function acquireTransactionLock(Connection $connection, string $name, int $timeoutSeconds): bool
    {
        $this->requireOpenTransaction($connection, 'acquireTransactionLock');
        return (int)$connection->fetchOne('SELECT GET_LOCK(?, ?)', [$name, max(0, $timeoutSeconds)]) === 1;
    }

    public function releaseTransactionLock(Connection $connection, string $name): void
    {
        $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
    }

    public function setTransactionTimeouts(Connection $connection, ?int $lockWaitTimeoutSeconds, ?int $statementTimeoutSeconds): void
    {
        $this->requireOpenTransaction($connection, 'setTransactionTimeouts');
        if ($lockWaitTimeoutSeconds !== null) {
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = ' . max(1, $lockWaitTimeoutSeconds));
        }
        if ($statementTimeoutSeconds !== null) {
            $connection->executeStatement('SET SESSION max_statement_time = ' . max(1, $statementTimeoutSeconds));
        }
    }

    public function clearTransactionTimeouts(Connection $connection): void
    {
        $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        $connection->executeStatement('SET SESSION max_statement_time = DEFAULT');
    }

    public function beginTransactionAtIsolationLevel(Connection $connection, int $isolationLevel): void
    {
        $isolationLevelSql = $this->getIsolationLevelSql($connection, $isolationLevel);
        // MariaDB: the statement sets the level of the NEXT transaction of the session only
        $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL ' . $isolationLevelSql);
        $connection->beginTransaction();
    }

    /**
     * The SQL name of a {@see \Doctrine\DBAL\TransactionIsolationLevel} constant, for a connection without an open
     * transaction.
     *
     * @throws InternalErrorException When $connection has an open transaction, or for an unknown level
     */
    protected function getIsolationLevelSql(Connection $connection, int $isolationLevel): string
    {
        if ($connection->isTransactionActive()) {
            throw new InternalErrorException(
                'SqlDialect::beginTransactionAtIsolationLevel() needs a connection without an open transaction.'
            );
        }
        return match ($isolationLevel) {
            TransactionIsolationLevel::READ_UNCOMMITTED => 'READ UNCOMMITTED',
            TransactionIsolationLevel::READ_COMMITTED => 'READ COMMITTED',
            TransactionIsolationLevel::REPEATABLE_READ => 'REPEATABLE READ',
            TransactionIsolationLevel::SERIALIZABLE => 'SERIALIZABLE',
            default => throw new InternalErrorException("Unknown transaction isolation level $isolationLevel."),
        };
    }

    /** @throws InternalErrorException When $connection has no open transaction */
    protected function requireOpenTransaction(Connection $connection, string $methodName): void
    {
        if (!$connection->isTransactionActive()) {
            throw new InternalErrorException("SqlDialect::$methodName() needs an open transaction on the connection.");
        }
    }
}
