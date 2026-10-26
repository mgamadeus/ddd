<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\AuthService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\QueryBuilder;
use Throwable;

/**
 * PostgreSQL write path (dual-dialect architecture plan §4.2/§4.6/§4.7). Structurally mirrors
 * {@see \DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager}'s MariaDB methods (same metadata/reflection walk,
 * same rights-check shape) but renders `INSERT … ON CONFLICT (<target>) DO UPDATE … RETURNING "id"` instead of
 * `INSERT … ON DUPLICATE KEY UPDATE` and uses a savepoint instead of MariaDB's "errors inside a transaction don't
 * abort it" tolerance (§4.7).
 *
 * Built here: identity catch-up after an explicit id ({@see PostgresIdentityCatchUp}, `nextval()` only), the
 * nullable-conflict-key runtime fallback (Mig §3.3's "`ON CONFLICT DO NOTHING` + per-key lookup", {@see
 * self::upsertWithNullableFallback()}) and `#[UpsertConflictKey]` (via {@see ConflictTargetResolver}).
 *
 * Still deliberately NOT built (no fixture needs it): the insert-vs-update `RETURNING … (old.id IS NULL) AS
 * inserted` the plan's §4.6 table shows — simplified to plain `RETURNING "id"` since no caller here or in the
 * fixtures distinguishes the two outcomes; and the LAZY identity catch-up retry on a generated id's PK 23505
 * (see {@see PostgresIdentityCatchUp}'s docblock).
 */
final class PostgresUpsertBuilder implements UpsertBuilder
{
    /** DDL sqlTypes rendered as `bytea` by {@see \DDD\Domain\Base\Repo\DB\Dialect\Schema\PostgresDdlRenderer}. */
    protected const array BLOB_SQL_TYPES = [
        DatabaseColumn::SQL_TYPE_BLOB => true,
        DatabaseColumn::SQL_TYPE_MEDIUMBLOB => true,
        DatabaseColumn::SQL_TYPE_LONGBLOB => true,
    ];

    public function upsert(
        DoctrineEntityManager $entityManager,
        DoctrineModel &$doctrineModel,
        ?QueryBuilder $updateRightsQueryBuilder = null,
        ?array $onlyFieldNames = null
    ): ?int {
        $connection = $entityManager->getConnection();
        $metadata = $entityManager->getClassMetadata($doctrineModel::class);
        $reflectionClass = ReflectionClass::instance($doctrineModel::class);

        $rightsScopedUpdatePath = $updateRightsQueryBuilder !== null && isset($doctrineModel->id) && $doctrineModel->id;

        ['columns' => $columnWrites, 'idValue' => $idValue] = $this->buildColumnWrites(
            $metadata,
            $doctrineModel,
            $reflectionClass,
            $onlyFieldNames
        );

        if ($rightsScopedUpdatePath) {
            return $this->rightsScopedUpdate($doctrineModel, $updateRightsQueryBuilder, $columnWrites, $connection);
        }

        $table = $doctrineModel->getTableName();
        $databaseModel = DatabaseModel::fromEntityClass($doctrineModel::ENTITY_CLASS);
        $presentColumnNames = array_map(static fn (ColumnWrite $columnWrite): string => $columnWrite->columnName, $columnWrites);
        $conflictResolution = ConflictTargetResolver::resolve($databaseModel, $presentColumnNames, $idValue !== null);

        $checkInsertRights = $updateRightsQueryBuilder !== null && !isset($doctrineModel->id);

        if ($conflictResolution->needsNullableFallback) {
            if (!$checkInsertRights) {
                return WriteExecutor::runSavepointed(
                    $connection,
                    function () use ($connection, $table, $columnWrites, $conflictResolution, &$doctrineModel): ?int {
                        $entityId = $this->upsertWithNullableFallback($connection, $table, $columnWrites, $conflictResolution->candidateKeys);
                        $doctrineModel->id = $entityId;
                        return $entityId;
                    }
                );
            }

            return WriteExecutor::runInOwnOrCallerTransaction(
                $connection,
                function () use ($connection, $table, $columnWrites, $conflictResolution, &$doctrineModel, $updateRightsQueryBuilder): int {
                    $entityId = $this->upsertWithNullableFallback($connection, $table, $columnWrites, $conflictResolution->candidateKeys);
                    $doctrineModel->id = $entityId;
                    $this->checkInsertRightsOrThrow($doctrineModel, $updateRightsQueryBuilder, $entityId);
                    return $entityId;
                }
            );
        }

        [$sql, $values, $types] = $this->renderUpsertSql($table, $columnWrites, $idValue, $conflictResolution->columns, false);

        if (!$checkInsertRights) {
            return WriteExecutor::runSavepointed(
                $connection,
                static function () use ($connection, $sql, $values, $types, $idValue, $table, &$doctrineModel): ?int {
                    $row = $connection->fetchAssociative($sql, $values, $types);
                    if ($row === false || !isset($row['id'])) {
                        return null;
                    }
                    $entityId = (int)$row['id'];
                    $doctrineModel->id = $entityId;
                    if ($idValue !== null) {
                        PostgresIdentityCatchUp::afterExplicitIdInsert($connection, $table, $entityId);
                    }
                    return $entityId;
                }
            );
        }

        return WriteExecutor::runInOwnOrCallerTransaction(
            $connection,
            function () use ($connection, $sql, $values, $types, $idValue, $table, &$doctrineModel, $updateRightsQueryBuilder): int {
                $row = $connection->fetchAssociative($sql, $values, $types);
                $entityId = (int)$row['id'];
                $doctrineModel->id = $entityId;
                if ($idValue !== null) {
                    PostgresIdentityCatchUp::afterExplicitIdInsert($connection, $table, $entityId);
                }
                $this->checkInsertRightsOrThrow($doctrineModel, $updateRightsQueryBuilder, $entityId);
                return $entityId;
            }
        );
    }

    public function updateColumnsById(DoctrineEntityManager $entityManager, DoctrineModel &$doctrineModel, array $fieldNames): ?int
    {
        if (!isset($doctrineModel->id) || !$doctrineModel->id) {
            return null;
        }
        $connection = $entityManager->getConnection();
        $metadata = $entityManager->getClassMetadata($doctrineModel::class);
        $reflectionClass = ReflectionClass::instance($doctrineModel::class);
        $table = $doctrineModel->getTableName();
        $entityId = (int)$doctrineModel->id;

        $assignments = [];
        $values = [];
        $types = [];
        foreach ($metadata->getFieldNames() as $fieldName) {
            if (
                isset($doctrineModel::$virtualColumns[$fieldName])
                || $metadata->isIdentifier($fieldName)
                || !in_array($fieldName, $fieldNames, true)
            ) {
                continue;
            }
            $reflectionProperty = $reflectionClass->getProperty($fieldName);
            if (!$reflectionProperty->isInitialized($doctrineModel)) {
                continue;
            }
            $columnWrite = $this->buildColumnWrite($metadata, $doctrineModel, $reflectionClass, $fieldName);
            [$assignmentSql, $assignmentValues, $assignmentTypes] = $this->renderUpdateByIdAssignment($columnWrite);
            $assignments[] = $assignmentSql;
            array_push($values, ...$assignmentValues);
            array_push($types, ...$assignmentTypes);
        }

        return WriteExecutor::runSavepointed(
            $connection,
            static function () use ($connection, $table, $assignments, $values, $types, $entityId): ?int {
                if ($assignments !== []) {
                    $affectedRows = (int)$connection->executeStatement(
                        'UPDATE "' . $table . '" SET ' . implode(', ', $assignments) . ' WHERE "id" = ?',
                        [...$values, $entityId],
                        [...$types, 'integer']
                    );
                    if ($affectedRows > 0) {
                        return $entityId;
                    }
                }
                $rowExists = $connection->fetchOne('SELECT 1 FROM "' . $table . '" WHERE "id" = ?', [$entityId], ['integer']) !== false;
                return $rowExists ? $entityId : null;
            }
        );
    }

    /**
     * @param DoctrineModel[] $doctrineModels
     */
    public function upsertMultiple(DoctrineEntityManager $entityManager, array &$doctrineModels, bool $useInsertIgnore = false): void
    {
        if ($doctrineModels === []) {
            return;
        }
        $connection = $entityManager->getConnection();
        $firstModel = reset($doctrineModels);
        $metadata = $entityManager->getClassMetadata($firstModel::class);
        $reflectionClass = ReflectionClass::instance($firstModel::class);
        $table = $firstModel->getTableName();
        $databaseModel = DatabaseModel::fromEntityClass($firstModel::ENTITY_CLASS);

        $rowShapes = [];
        $rowColumnWrites = [];
        $rowIdValues = [];
        foreach ($doctrineModels as $index => $doctrineModel) {
            ['columns' => $columnWrites, 'idValue' => $idValue] = $this->buildColumnWrites(
                $metadata,
                $doctrineModel,
                $reflectionClass,
                null
            );
            $fieldNames = array_map(static fn (ColumnWrite $columnWrite): string => $columnWrite->columnName, $columnWrites);
            if ($idValue !== null) {
                $fieldNames[] = '#id'; // "#" cannot collide with a real column name — kept out of $columnWrites itself
            }
            $columnsToReplace = array_values(array_intersect($doctrineModel->columnsToReplaceInsteadOfMerge, $fieldNames));
            sort($columnsToReplace);
            $rowShapes[$index] = ['fieldNames' => $fieldNames, 'columnsToReplace' => $columnsToReplace];
            $rowColumnWrites[$index] = $columnWrites;
            $rowIdValues[$index] = $idValue;
        }

        $conflictTargetByIndex = [];
        $conflictKeyValueByIndex = [];
        $needsFallbackByIndex = [];
        $fallbackCandidateKeysByIndex = [];
        foreach ($doctrineModels as $index => $doctrineModel) {
            $hasId = $rowIdValues[$index] !== null;
            $presentColumnNames = array_map(
                static fn (ColumnWrite $columnWrite): string => $columnWrite->columnName,
                $rowColumnWrites[$index]
            );
            if ($useInsertIgnore) {
                $conflictTargetByIndex[$index] = [];
                $conflictKeyValueByIndex[$index] = null;
                continue;
            }
            $resolution = ConflictTargetResolver::resolve($databaseModel, $presentColumnNames, $hasId);
            if ($resolution->needsNullableFallback) {
                // ON CONFLICT has no single target for this row — the fallback runs its own INSERT, so it must
                // never share a multi-row statement with another row (BatchPlanner's rowByRow forcing below).
                $conflictTargetByIndex[$index] = [];
                $needsFallbackByIndex[$index] = true;
                $fallbackCandidateKeysByIndex[$index] = $resolution->candidateKeys;
                $conflictKeyValueByIndex[$index] = spl_object_id($doctrineModel) . '#fallback'; // unique → never dedups into another row's key
                continue;
            }
            $target = $resolution->columns;
            $conflictTargetByIndex[$index] = $target;
            if ($target === []) {
                $conflictKeyValueByIndex[$index] = null;
                continue;
            }
            if ($hasId) {
                $conflictKeyValueByIndex[$index] = 'id=' . $rowIdValues[$index];
                continue;
            }
            $keyParts = [];
            foreach ($target as $columnName) {
                $columnWrite = $this->findColumnWrite($rowColumnWrites[$index], $columnName);
                $value = $columnWrite?->value;
                $keyParts[] = is_scalar($value) || $value === null ? (string)$value : json_encode($value);
            }
            $conflictKeyValueByIndex[$index] = implode("\0", $keyParts);
        }

        $runs = BatchPlanner::planRuns($rowShapes, static fn (int $index) => $conflictKeyValueByIndex[$index]);
        foreach ($runs as &$run) {
            foreach ($run['modelIndexes'] as $index) {
                if (isset($needsFallbackByIndex[$index])) {
                    $run['rowByRow'] = true;
                    break;
                }
            }
        }
        unset($run);

        $executeRuns = function () use (
            $connection,
            $runs,
            $doctrineModels,
            $rowColumnWrites,
            $rowIdValues,
            $conflictTargetByIndex,
            $needsFallbackByIndex,
            $fallbackCandidateKeysByIndex,
            $table,
            $useInsertIgnore
        ): void {
            foreach ($runs as $run) {
                if (!$run['rowByRow'] && count($run['modelIndexes']) > 1) {
                    $this->executeBulkRun(
                        $connection,
                        $table,
                        $run['modelIndexes'],
                        $rowColumnWrites,
                        $rowIdValues,
                        $conflictTargetByIndex,
                        $useInsertIgnore
                    );
                    continue;
                }
                foreach ($run['modelIndexes'] as $index) {
                    if (isset($needsFallbackByIndex[$index])) {
                        $this->upsertWithNullableFallback(
                            $connection,
                            $table,
                            $rowColumnWrites[$index],
                            $fallbackCandidateKeysByIndex[$index]
                        );
                        continue;
                    }
                    [$sql, $values, $types] = $this->renderUpsertSql(
                        $table,
                        $rowColumnWrites[$index],
                        $rowIdValues[$index],
                        $conflictTargetByIndex[$index],
                        $useInsertIgnore
                    );
                    $connection->fetchAssociative($sql, $values, $types);
                }
            }

            // nextval() is not transactional (§4.6) — safe to run before the caller's own commit/rollback decides
            // the batch's fate, and cheaper than one call per explicit-id row.
            $maxExplicitId = null;
            foreach ($rowIdValues as $idValue) {
                if ($idValue !== null && ($maxExplicitId === null || $idValue > $maxExplicitId)) {
                    $maxExplicitId = $idValue;
                }
            }
            if ($maxExplicitId !== null) {
                PostgresIdentityCatchUp::afterExplicitIdInsert($connection, $table, (int)$maxExplicitId);
            }
        };

        $totalStatements = 0;
        foreach ($runs as $run) {
            $totalStatements += (!$run['rowByRow'] && count($run['modelIndexes']) > 1) ? 1 : count($run['modelIndexes']);
        }
        if ($totalStatements <= 1) {
            $executeRuns();
            return;
        }

        if (!$connection->isTransactionActive()) {
            $connection->beginTransaction();
            try {
                WriteExecutor::withoutWriteSavepoint($executeRuns);
                $connection->commit();
            } catch (Throwable $throwable) {
                if ($connection->isTransactionActive()) {
                    try {
                        $connection->rollBack();
                    } catch (Throwable) {
                    }
                }
                throw $throwable;
            }
            return;
        }

        WriteExecutor::runSavepointed(
            $connection,
            static fn () => WriteExecutor::withoutWriteSavepoint($executeRuns)
        );
    }

    public function insertOrNull(DoctrineEntityManager $entityManager, DoctrineModel &$doctrineModel): ?int
    {
        $connection = $entityManager->getConnection();
        $metadata = $entityManager->getClassMetadata($doctrineModel::class);
        $reflectionClass = ReflectionClass::instance($doctrineModel::class);
        ['columns' => $columnWrites, 'idValue' => $idValue] = $this->buildColumnWrites($metadata, $doctrineModel, $reflectionClass, null);
        $table = $doctrineModel->getTableName();
        [$columns, $placeholders, $values, $types] = $this->renderInsertColumnsAndValues($columnWrites, $idValue);
        $sql = 'INSERT INTO "' . $table . '" (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ') RETURNING "id"';

        try {
            return WriteExecutor::runSavepointed(
                $connection,
                static function () use ($connection, $sql, $values, $types, $idValue, $table, &$doctrineModel): int {
                    $row = $connection->fetchAssociative($sql, $values, $types);
                    $entityId = (int)$row['id'];
                    $doctrineModel->id = $entityId;
                    if ($idValue !== null) {
                        PostgresIdentityCatchUp::afterExplicitIdInsert($connection, $table, $entityId);
                    }
                    return $entityId;
                }
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    // ───────────────────────────────────────────────────────── rights-scoped update (id + rights query, never inserts)

    protected function rightsScopedUpdate(
        DoctrineModel $doctrineModel,
        QueryBuilder $updateRightsQueryBuilder,
        array $columnWrites,
        Connection $connection
    ): int {
        $modelAlias = $doctrineModel::MODEL_ALIAS;
        $table = $doctrineModel->getTableName();

        $assignments = [];
        $values = [];
        $types = [];
        foreach ($columnWrites as $columnWrite) {
            [$assignmentSql, $assignmentValues, $assignmentTypes] = $this->renderUpdateByIdAssignment($columnWrite);
            $assignments[] = $assignmentSql;
            array_push($values, ...$assignmentValues);
            array_push($types, ...$assignmentTypes);
        }

        $rightsIdQueryBuilder = clone $updateRightsQueryBuilder;
        $rightsIdQueryBuilder->select("$modelAlias.id");
        $rightsIdQuery = $rightsIdQueryBuilder->getQuery();
        $rightsSelectSql = $rightsIdQuery->getSQL();
        $rightsParserResult = (new Parser($rightsIdQuery))->parse();
        $rightsParameterMappings = $rightsParserResult->getParameterMappings();
        $rightsValuesBySqlPosition = [];
        foreach ($rightsIdQuery->getParameters() as $rightsParameter) {
            foreach ($rightsParameterMappings[$rightsParameter->getName()] ?? [] as $sqlPosition) {
                $rightsValuesBySqlPosition[$sqlPosition] = $rightsParameter->getValue();
            }
        }
        ksort($rightsValuesBySqlPosition);
        $rightsParameterValues = [];
        $rightsParameterTypes = [];
        foreach ($rightsValuesBySqlPosition as $rightsParameterValue) {
            if ($rightsParameterValue instanceof \DateTimeInterface) {
                $rightsParameterValue = $rightsParameterValue->format('Y-m-d H:i:s');
            }
            if (is_array($rightsParameterValue)) {
                $rightsParameterTypes[] = is_int($rightsParameterValue[array_key_first($rightsParameterValue)] ?? null)
                    ? ArrayParameterType::INTEGER
                    : ArrayParameterType::STRING;
            } elseif (is_int($rightsParameterValue)) {
                $rightsParameterTypes[] = 'integer';
            } elseif (is_bool($rightsParameterValue)) {
                $rightsParameterTypes[] = 'boolean';
            } else {
                $rightsParameterTypes[] = 'string';
            }
            $rightsParameterValues[] = $rightsParameterValue;
        }

        return WriteExecutor::runSavepointed(
            $connection,
            function () use (
                $connection,
                $table,
                $assignments,
                $values,
                $types,
                $doctrineModel,
                $rightsSelectSql,
                $rightsParameterValues,
                $rightsParameterTypes,
                $modelAlias,
                $updateRightsQueryBuilder
            ): int {
                $affectedRows = 0;
                if ($assignments !== []) {
                    $sql = 'UPDATE "' . $table . '" t SET ' . implode(', ', $assignments)
                        . ' WHERE t."id" = ? AND t."id" IN (SELECT * FROM (' . $rightsSelectSql . ') dddUpdateRightsScope)';
                    $affectedRows = (int)$connection->executeStatement(
                        $sql,
                        [...$values, $doctrineModel->id, ...$rightsParameterValues],
                        [...$types, 'integer', ...$rightsParameterTypes]
                    );
                }
                if ($affectedRows === 0) {
                    $checkRightsQueryBuilder = clone $updateRightsQueryBuilder;
                    $checkRightsQueryBuilder->andWhere("$modelAlias.id = :entityId")->setParameter('entityId', $doctrineModel->id);
                    $visible = $checkRightsQueryBuilder->getQuery()->setMaxResults(1)->getResult()[0] ?? null;
                    if (!$visible) {
                        $this->throwForbidden($doctrineModel);
                    }
                }
                return (int)$doctrineModel->id;
            }
        );
    }

    protected function checkInsertRightsOrThrow(DoctrineModel $doctrineModel, QueryBuilder $updateRightsQueryBuilder, int $entityId): void
    {
        $modelAlias = $doctrineModel::MODEL_ALIAS;
        $checkRightsQueryBuilder = clone $updateRightsQueryBuilder;
        $checkRightsQueryBuilder->andWhere("$modelAlias.id = :entityId")->setParameter('entityId', $entityId);
        $result = $checkRightsQueryBuilder->getQuery()->setMaxResults(1)->getResult();
        if (!($result[0] ?? null)) {
            $this->throwForbidden($doctrineModel);
        }
    }

    protected function throwForbidden(DoctrineModel $doctrineModel): never
    {
        $authAccount = AuthService::instance()->getAccount() ?? null;
        $authAccountId = $authAccount ? $authAccount->id : '(not logged in)';
        throw new ForbiddenException(
            'Account ' . $authAccountId . ' has no permission to update or create Entity ' . $doctrineModel::ENTITY_CLASS
        );
    }

    // ───────────────────────────────────────────────────────── column walk (shared with MariaDbUpsertBuilder's sibling logic)

    /**
     * @return array{columns: ColumnWrite[], idValue: int|string|null}
     */
    protected function buildColumnWrites(
        ClassMetadata $metadata,
        DoctrineModel $doctrineModel,
        ReflectionClass $reflectionClass,
        ?array $onlyFieldNames
    ): array {
        $columns = [];
        $idValue = null;
        foreach ($metadata->getFieldNames() as $fieldName) {
            if (isset($doctrineModel::$virtualColumns[$fieldName])) {
                continue;
            }
            if ($metadata->isIdentifier($fieldName)) {
                $value = $metadata->getFieldValue($doctrineModel, $fieldName);
                if ($value !== null && $value) {
                    $idValue = $value;
                }
                continue;
            }
            if ($onlyFieldNames !== null && !in_array($fieldName, $onlyFieldNames, true)) {
                continue;
            }
            $reflectionProperty = $reflectionClass->getProperty($fieldName);
            if (!$reflectionProperty->isInitialized($doctrineModel)) {
                continue;
            }
            $columns[] = $this->buildColumnWrite($metadata, $doctrineModel, $reflectionClass, $fieldName);
        }
        return ['columns' => $columns, 'idValue' => $idValue];
    }

    protected function buildColumnWrite(
        ClassMetadata $metadata,
        DoctrineModel $doctrineModel,
        ReflectionClass $reflectionClass,
        string $fieldName
    ): ColumnWrite {
        $value = $metadata->getFieldValue($doctrineModel, $fieldName);
        $reflectionProperty = $reflectionClass->getProperty($fieldName);
        /** @var DatabaseColumn|null $databaseColumnAttributeInstance */
        $databaseColumnAttributeInstance = $reflectionProperty->getAttributeInstance(DatabaseColumn::class);

        if ($databaseColumnAttributeInstance?->isMergableJSONColumn && is_string($value) && $value !== '') {
            $decodedMergableValue = json_decode($value, true);
            if (is_array($decodedMergableValue)) {
                $value = $decodedMergableValue;
            }
        }

        $type = $metadata->getTypeOfField($fieldName);
        $columnName = $metadata->getColumnName($fieldName);
        $kind = BindExpressionKind::PLAIN;
        $vectorDimensions = null;
        $dbalType = $type;

        if (isset(DatabaseColumn::SPATIAL_SQL_TYPES[$type])) {
            $kind = BindExpressionKind::GEOMETRY;
            $dbalType = 'string';
            if ($value !== null) {
                $value = (string)$value;
            }
        } elseif ($type === 'vector') {
            $kind = BindExpressionKind::VECTOR;
            $dbalType = 'string';
            $vectorDimensions = (int)($metadata->fieldMappings[$fieldName]['length'] ?? 0);
            if ($value !== null) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
        } elseif ($this->isBlobColumn($doctrineModel, $fieldName)) {
            // A BLOB column is `bytea` on PostgreSQL, but its Doctrine field type is usually `string`: bound as text,
            // binary content (gzip, images) fails with "invalid byte sequence for encoding UTF8". `binary` binds
            // ParameterType::BINARY (PDO::PARAM_LOB), which pdo_pgsql sends as bytea.
            $dbalType = 'binary';
        }

        $updatePolicy = UpdatePolicy::REPLACE;
        $rawOnUpdateAction = null;
        if ($databaseColumnAttributeInstance?->isMergableJSONColumn && is_array($value)) {
            $updatePolicy = in_array($fieldName, $doctrineModel->columnsToReplaceInsteadOfMerge, true)
                ? UpdatePolicy::REPLACE
                : UpdatePolicy::MERGE_PATCH;
        } elseif ($fieldName === ChangeHistory::DEFAULT_CREATED_COLUMN_NAME) {
            $updatePolicy = UpdatePolicy::KEEP_CREATED;
        } elseif (isset($databaseColumnAttributeInstance->onUpdateAction)) {
            $updatePolicy = UpdatePolicy::RAW_ACTION;
            $rawOnUpdateAction = $databaseColumnAttributeInstance->onUpdateAction;
        }

        return new ColumnWrite($columnName, $value, $dbalType, $kind, $vectorDimensions, $updatePolicy, $rawOnUpdateAction);
    }

    /**
     * Whether the entity property behind $fieldName declares a BLOB sqlType. The attribute sits on the entity
     * (`#[DatabaseColumn(sqlType: …LONGBLOB)]`), not on the generated model, whose column is a plain `string`.
     * Cached per model class and field.
     */
    protected function isBlobColumn(DoctrineModel $doctrineModel, string $fieldName): bool
    {
        static $blobColumnCache = [];
        $cacheKey = $doctrineModel::class . '::' . $fieldName;
        if (isset($blobColumnCache[$cacheKey])) {
            return $blobColumnCache[$cacheKey];
        }
        $isBlobColumn = false;
        $entityClass = $doctrineModel::ENTITY_CLASS;
        if ($entityClass !== '' && class_exists($entityClass) && property_exists($entityClass, $fieldName)) {
            /** @var DatabaseColumn|null $databaseColumnAttributeInstance */
            $databaseColumnAttributeInstance = (new ReflectionClass($entityClass))->getProperty($fieldName)
                ->getAttributeInstance(DatabaseColumn::class);
            $isBlobColumn = isset(self::BLOB_SQL_TYPES[$databaseColumnAttributeInstance?->sqlType ?? '']);
        }

        return $blobColumnCache[$cacheKey] = $isBlobColumn;
    }

    protected function findColumnWrite(array $columnWrites, string $columnName): ?ColumnWrite
    {
        foreach ($columnWrites as $columnWrite) {
            if ($columnWrite->columnName === $columnName) {
                return $columnWrite;
            }
        }
        return null;
    }

    // ───────────────────────────────────────────────────────── nullable-conflict-key runtime fallback (Mig §3.3)

    /** @var int "counted" (Mig §3.3) — how many times the fallback actually had to look a row up, process-lifetime. */
    protected static int $nullableFallbackLookupCount = 0;

    public static function nullableFallbackLookupCount(): int
    {
        return self::$nullableFallbackLookupCount;
    }

    /**
     * Mig §3.3 "Runtime fallback for the nullable cases": {@see ConflictTargetResolver::resolve()} could not name
     * one safe `ON CONFLICT` target for this row ($candidateKeys has 2+ ambiguous UNIQUE keys, at least one with
     * a nullable column, no `#[UpsertConflictKey]`). A target-less `ON CONFLICT DO NOTHING RETURNING id` absorbs
     * a collision on ANY of the table's unique constraints without naming which one; when it inserted nothing,
     * $candidateKeys is tried in order to find the row that actually collided, and that row is UPDATEd by id —
     * "counted and logged" ({@see self::$nullableFallbackLookupCount}, `error_log()`) because every hit is an
     * extra two round trips this entity's shape could avoid entirely with a declared `#[UpsertConflictKey]`.
     *
     * @param ColumnWrite[] $columnWrites
     * @param string[][] $candidateKeys
     */
    protected function upsertWithNullableFallback(Connection $connection, string $table, array $columnWrites, array $candidateKeys): int
    {
        [$sql, $values, $types] = $this->renderUpsertSql($table, $columnWrites, null, [], true);
        $row = $connection->fetchAssociative($sql, $values, $types);
        if ($row !== false && isset($row['id'])) {
            return (int)$row['id'];
        }

        foreach ($candidateKeys as $keyColumns) {
            $conditions = [];
            $lookupValues = [];
            $lookupTypes = [];
            foreach ($keyColumns as $columnName) {
                $columnWrite = $this->findColumnWrite($columnWrites, $columnName);
                if ($columnWrite === null) {
                    continue 2; // this row never wrote one of this key's columns — not a candidate for THIS row
                }
                $conditions[] = '"' . $columnName . '" = ?';
                $lookupValues[] = $columnWrite->value;
                $lookupTypes[] = $columnWrite->dbalType;
            }
            $existingId = $connection->fetchOne(
                'SELECT "id" FROM "' . $table . '" WHERE ' . implode(' AND ', $conditions),
                $lookupValues,
                $lookupTypes
            );
            if ($existingId === false) {
                continue;
            }

            $entityId = (int)$existingId;
            $assignments = [];
            $updateValues = [];
            $updateTypes = [];
            foreach ($columnWrites as $columnWrite) {
                [$assignmentSql, $assignmentValues, $assignmentTypes] = $this->renderUpdateByIdAssignment($columnWrite);
                $assignments[] = $assignmentSql;
                array_push($updateValues, ...$assignmentValues);
                array_push($updateTypes, ...$assignmentTypes);
            }
            if ($assignments !== []) {
                $connection->executeStatement(
                    'UPDATE "' . $table . '" SET ' . implode(', ', $assignments) . ' WHERE "id" = ?',
                    [...$updateValues, $entityId],
                    [...$updateTypes, 'integer']
                );
            }

            self::$nullableFallbackLookupCount++;
            error_log(
                'ConflictTargetResolver nullable-key fallback: "' . $table . '" row ' . $entityId
                . ' matched candidate key (' . implode(', ', $keyColumns) . ') after the target-less INSERT found '
                . 'no row to return — no #[UpsertConflictKey] declared on this entity.'
            );
            return $entityId;
        }

        throw new InternalErrorException(
            'ConflictTargetResolver nullable-key fallback: "' . $table . '" rejected the insert as a duplicate '
            . 'but no candidate UNIQUE key lookup found the existing row (a concurrent delete between the INSERT '
            . 'and the lookup, or a collation mismatch between the write and this SELECT).'
        );
    }

    // ───────────────────────────────────────────────────────── SQL rendering

    /** @return array{0: string, 1: mixed, 2: bool} [SQL fragment for the `?` slot, the value to bind, whether a value is bound at all] */
    protected function valueExpressionFor(ColumnWrite $columnWrite): array
    {
        if ($columnWrite->kind === BindExpressionKind::GEOMETRY) {
            return $columnWrite->value !== null
                ? ['ST_GeomFromText(?, 0)', $columnWrite->value, true]
                : ['?', null, true];
        }
        if ($columnWrite->kind === BindExpressionKind::VECTOR) {
            if ($columnWrite->value !== null) {
                return ['?::vector', $columnWrite->value, true];
            }
            if (($columnWrite->vectorDimensions ?? 0) > 0) {
                return [$this->postgresNullVectorLiteral($columnWrite->vectorDimensions), null, false];
            }
            return ['?', null, true];
        }
        return ['?', $columnWrite->value, true];
    }

    protected function postgresNullVectorLiteral(int $dimensions): string
    {
        return "'[" . implode(',', array_fill(0, $dimensions, '0')) . "]'::vector";
    }

    /** @return array{0: string[], 1: string[], 2: array, 3: string[]} [quoted columns, value expressions, values, types] */
    protected function renderInsertColumnsAndValues(array $columnWrites, int|string|null $idValue): array
    {
        $columns = [];
        $placeholders = [];
        $values = [];
        $types = [];
        if ($idValue !== null) {
            $columns[] = '"id"';
            $placeholders[] = '?';
            $values[] = $idValue;
            $types[] = 'integer';
        }
        foreach ($columnWrites as $columnWrite) {
            [$expr, $value, $bound] = $this->valueExpressionFor($columnWrite);
            $columns[] = '"' . $columnWrite->columnName . '"';
            $placeholders[] = $expr;
            if ($bound) {
                $values[] = $value;
                $types[] = $columnWrite->dbalType;
            }
        }
        return [$columns, $placeholders, $values, $types];
    }

    /** @return array{0: string, 1: array, 2: string[]} [SQL, values, types] */
    protected function renderUpsertSql(
        string $table,
        array $columnWrites,
        int|string|null $idValue,
        array $conflictTargetColumns,
        bool $useInsertIgnore
    ): array {
        [$columns, $placeholders, $values, $types] = $this->renderInsertColumnsAndValues($columnWrites, $idValue);
        $sql = 'INSERT INTO "' . $table . '" AS t (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        if ($useInsertIgnore) {
            $sql .= ' ON CONFLICT DO NOTHING';
        } elseif ($conflictTargetColumns !== []) {
            $assignments = [];
            foreach ($columnWrites as $columnWrite) {
                $assignments[] = $this->renderConflictUpdateAssignment($columnWrite);
            }
            $targetColumns = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $conflictTargetColumns));
            $sql .= $assignments === []
                ? " ON CONFLICT ($targetColumns) DO NOTHING"
                : " ON CONFLICT ($targetColumns) DO UPDATE SET " . implode(', ', $assignments);
        }

        $sql .= ' RETURNING "id"';
        return [$sql, $values, $types];
    }

    protected function executeBulkRun(
        Connection $connection,
        string $table,
        array $modelIndexes,
        array $rowColumnWrites,
        array $rowIdValues,
        array $conflictTargetByIndex,
        bool $useInsertIgnore
    ): void {
        $firstIndex = $modelIndexes[0];
        /** @var ColumnWrite[] $columnWritesTemplate */
        $columnWritesTemplate = $rowColumnWrites[$firstIndex];
        $hasId = $rowIdValues[$firstIndex] !== null;
        $conflictTarget = $conflictTargetByIndex[$firstIndex];

        $columnNames = [];
        if ($hasId) {
            $columnNames[] = '"id"';
        }
        foreach ($columnWritesTemplate as $columnWrite) {
            $columnNames[] = '"' . $columnWrite->columnName . '"';
        }

        $rowPlaceholderGroups = [];
        $values = [];
        $types = [];
        foreach ($modelIndexes as $index) {
            $rowExpressions = [];
            if ($hasId) {
                $rowExpressions[] = '?';
                $values[] = $rowIdValues[$index];
                $types[] = 'integer';
            }
            foreach ($rowColumnWrites[$index] as $columnWrite) {
                [$expr, $value, $bound] = $this->valueExpressionFor($columnWrite);
                $rowExpressions[] = $expr;
                if ($bound) {
                    $values[] = $value;
                    $types[] = $columnWrite->dbalType;
                }
            }
            $rowPlaceholderGroups[] = '(' . implode(', ', $rowExpressions) . ')';
        }

        $sql = 'INSERT INTO "' . $table . '" AS t (' . implode(', ', $columnNames) . ') VALUES ' . implode(', ', $rowPlaceholderGroups);
        if ($useInsertIgnore) {
            $sql .= ' ON CONFLICT DO NOTHING';
        } elseif ($conflictTarget !== []) {
            $assignments = array_map(
                fn (ColumnWrite $columnWrite): string => $this->renderConflictUpdateAssignment($columnWrite),
                $columnWritesTemplate
            );
            $targetColumns = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $conflictTarget));
            $sql .= $assignments === []
                ? " ON CONFLICT ($targetColumns) DO NOTHING"
                : " ON CONFLICT ($targetColumns) DO UPDATE SET " . implode(', ', $assignments);
        }

        $connection->executeStatement($sql, $values, $types);
    }

    protected function renderConflictUpdateAssignment(ColumnWrite $columnWrite): string
    {
        $column = '"' . $columnWrite->columnName . '"';
        return match ($columnWrite->updatePolicy) {
            UpdatePolicy::RAW_ACTION => $this->renderRawActionForConflictForm($columnWrite),
            UpdatePolicy::MERGE_PATCH => "$column = ddd_json_merge_patch(COALESCE(t.$column,'{}'), COALESCE(EXCLUDED.$column,'{}'))",
            UpdatePolicy::KEEP_CREATED => "$column = COALESCE(EXCLUDED.$column, t.$column)",
            default => "$column = EXCLUDED.$column",
        };
    }

    /** @return array{0: string, 1: array, 2: string[]} [assignment SQL, values, types] */
    protected function renderUpdateByIdAssignment(ColumnWrite $columnWrite): array
    {
        if ($columnWrite->updatePolicy === UpdatePolicy::RAW_ACTION) {
            return $this->renderRawActionForUpdateForm($columnWrite);
        }
        [$expr, $value, $bound] = $this->valueExpressionFor($columnWrite);
        $column = '"' . $columnWrite->columnName . '"';
        $values = $bound ? [$value] : [];
        $types = $bound ? [$columnWrite->dbalType] : [];
        if ($columnWrite->updatePolicy === UpdatePolicy::MERGE_PATCH) {
            return ["$column = ddd_json_merge_patch(COALESCE($column,'{}'), $expr)", $values, $types];
        }
        if ($columnWrite->updatePolicy === UpdatePolicy::KEEP_CREATED) {
            return ["$column = COALESCE($expr, $column)", $values, $types];
        }
        return ["$column = $expr", $values, $types];
    }

    /**
     * Translates one `onUpdateAction` (MariaDB SQL written against `VALUES(`col`)`) into its `ON CONFLICT DO
     * UPDATE` form: `VALUES(`col`)` → `EXCLUDED."col"` (the column's own INSERT-bound value is already there —
     * no extra bind), any other backtick identifier → double-quoted. A bare action with no `VALUES()` at all
     * (`CounterRow::$keptValue`'s `` `keptValue` ``) survives untouched but double-quoted — an unqualified
     * column reference inside `DO UPDATE SET` already resolves to the target row's CURRENT value (the same rule
     * a plain `UPDATE` uses), so `"keptValue" = "keptValue"` is correctly a no-op.
     */
    protected function renderRawActionForConflictForm(ColumnWrite $columnWrite): string
    {
        $translated = preg_replace(
            '/VALUES\s*\(\s*`' . preg_quote($columnWrite->columnName, '/') . '`\s*\)/i',
            'EXCLUDED."' . $columnWrite->columnName . '"',
            (string)$columnWrite->rawOnUpdateAction
        );
        // Remaining backtick identifiers are bare references to the CURRENT row's value. Unlike MariaDB's
        // ODKU, PostgreSQL's DO UPDATE scope exposes EXCLUDED as a second relation with the SAME column names,
        // so a bare "counter" is genuinely ambiguous (42702) between the target row and EXCLUDED — verified
        // against a real PG 18 container. Qualifying with the target alias disambiguates it.
        $translated = preg_replace('/`([A-Za-z0-9_]+)`/', 't."$1"', $translated);
        return '"' . $columnWrite->columnName . '" = ' . $translated;
    }

    /**
     * Same translation for a plain `UPDATE … SET` context (no `EXCLUDED` pseudo-table there): `VALUES(`col`)`
     * becomes the column's own bind expression (`?`, `ST_GeomFromText(?, 0)`, …), binding the incoming value at
     * that exact position — mirrors
     * {@see \DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager::buildOnUpdateActionAssignment()}'s MariaDB
     * form. A bare `` `col` `` with no `VALUES()` binds nothing (self-reference, a no-op).
     *
     * @return array{0: string, 1: array, 2: string[]}
     */
    protected function renderRawActionForUpdateForm(ColumnWrite $columnWrite): array
    {
        $values = [];
        $types = [];
        [$expr, $value, $bound] = $this->valueExpressionFor($columnWrite);
        $translated = preg_replace_callback(
            '/VALUES\s*\(\s*`' . preg_quote($columnWrite->columnName, '/') . '`\s*\)/i',
            function () use ($expr, $value, $bound, $columnWrite, &$values, &$types): string {
                if ($bound) {
                    $values[] = $value;
                    $types[] = $columnWrite->dbalType;
                }
                return $expr;
            },
            (string)$columnWrite->rawOnUpdateAction
        );
        $translated = preg_replace('/`([A-Za-z0-9_]+)`/', '"$1"', $translated);
        return ['"' . $columnWrite->columnName . '" = ' . $translated, $values, $types];
    }
}
