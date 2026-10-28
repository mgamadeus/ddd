<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineBoundSql;
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
class PostgresUpsertBuilder implements UpsertBuilder
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

        $rowWrite = $this->buildColumnWrites($metadata, $doctrineModel, $reflectionClass, $onlyFieldNames);
        $columnWrites = $rowWrite->columnWrites;
        $idValue = $rowWrite->idValue;

        if ($rightsScopedUpdatePath) {
            return $this->rightsScopedUpdate($doctrineModel, $updateRightsQueryBuilder, $columnWrites, $connection);
        }

        $table = $doctrineModel->getTableName();
        $databaseModel = DatabaseModel::fromEntityClass($doctrineModel::ENTITY_CLASS);
        $presentColumnNames = $columnWrites->getColumnNames();
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

        $upsertSql = $this->renderUpsertSql($table, $columnWrites, $idValue, $conflictResolution->columns, false);
        $sql = $upsertSql->sql;
        $values = $upsertSql->values;
        $types = $upsertSql->types;

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
            $assignment = $this->renderUpdateByIdAssignment($columnWrite);
            $assignments[] = $assignment->sql;
            array_push($values, ...$assignment->values);
            array_push($types, ...$assignment->types);
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

        // positions in $rowShapes / $rowWrites are the batch's model indexes: the models must be a list
        $doctrineModels = array_values($doctrineModels);
        $rowShapes = new BatchRowShapes();
        $rowWrites = new RowWrites();
        foreach ($doctrineModels as $doctrineModel) {
            $rowWrite = $this->buildColumnWrites($metadata, $doctrineModel, $reflectionClass, null);
            $fieldNames = $rowWrite->columnWrites->getColumnNames();
            if ($rowWrite->idValue !== null) {
                $fieldNames[] = '#id'; // "#" cannot collide with a real column name — kept out of the column writes
            }
            $columnsToReplace = array_values(array_intersect($doctrineModel->columnsToReplaceInsteadOfMerge, $fieldNames));
            sort($columnsToReplace);
            $rowShape = new BatchRowShape($fieldNames, $columnsToReplace);
            $rowShapes->add($rowShape);
            $rowWrites->add($rowWrite);
        }
        $rowWriteList = $rowWrites->getElements();

        $conflictTargetByIndex = [];
        $conflictKeyValueByIndex = [];
        $needsFallbackByIndex = [];
        $fallbackCandidateKeysByIndex = [];
        foreach ($doctrineModels as $index => $doctrineModel) {
            $hasId = $rowWriteList[$index]->idValue !== null;
            $presentColumnNames = $rowWriteList[$index]->columnWrites->getColumnNames();
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
                $conflictKeyValueByIndex[$index] = 'id=' . $rowWriteList[$index]->idValue;
                continue;
            }
            $keyParts = [];
            foreach ($target as $columnName) {
                $columnWrite = $this->findColumnWrite($rowWriteList[$index]->columnWrites, $columnName);
                $value = $columnWrite?->value;
                $keyParts[] = is_scalar($value) || $value === null ? (string)$value : json_encode($value);
            }
            $conflictKeyValueByIndex[$index] = implode("\0", $keyParts);
        }

        $runs = BatchPlanner::planRuns($rowShapes, static fn (int $index) => $conflictKeyValueByIndex[$index]);
        foreach ($runs->getElements() as $run) {
            foreach ($run->modelIndexes as $index) {
                if (isset($needsFallbackByIndex[$index])) {
                    $run->rowByRow = true;
                    break;
                }
            }
        }

        $executeRuns = function () use (
            $connection,
            $runs,
            $doctrineModels,
            $rowWrites,
            $rowWriteList,
            $conflictTargetByIndex,
            $needsFallbackByIndex,
            $fallbackCandidateKeysByIndex,
            $table,
            $useInsertIgnore
        ): void {
            foreach ($runs->getElements() as $run) {
                if (!$run->rowByRow && count($run->modelIndexes) > 1) {
                    $this->executeBulkRun(
                        $connection,
                        $table,
                        $run->modelIndexes,
                        $rowWrites,
                        $conflictTargetByIndex,
                        $useInsertIgnore
                    );
                    continue;
                }
                foreach ($run->modelIndexes as $index) {
                    if (isset($needsFallbackByIndex[$index])) {
                        $this->upsertWithNullableFallback(
                            $connection,
                            $table,
                            $rowWriteList[$index]->columnWrites,
                            $fallbackCandidateKeysByIndex[$index]
                        );
                        continue;
                    }
                    $upsertSql = $this->renderUpsertSql(
                        $table,
                        $rowWriteList[$index]->columnWrites,
                        $rowWriteList[$index]->idValue,
                        $conflictTargetByIndex[$index],
                        $useInsertIgnore
                    );
                    $connection->fetchAssociative($upsertSql->sql, $upsertSql->values, $upsertSql->types);
                }
            }

            // nextval() is not transactional (§4.6) — safe to run before the caller's own commit/rollback decides
            // the batch's fate, and cheaper than one call per explicit-id row.
            $maxExplicitId = null;
            foreach ($rowWriteList as $rowWrite) {
                $idValue = $rowWrite->idValue;
                if ($idValue !== null && ($maxExplicitId === null || $idValue > $maxExplicitId)) {
                    $maxExplicitId = $idValue;
                }
            }
            if ($maxExplicitId !== null) {
                PostgresIdentityCatchUp::afterExplicitIdInsert($connection, $table, (int)$maxExplicitId);
            }
        };

        $totalStatements = 0;
        foreach ($runs->getElements() as $run) {
            $totalStatements += (!$run->rowByRow && count($run->modelIndexes) > 1) ? 1 : count($run->modelIndexes);
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
        $rowWrite = $this->buildColumnWrites($metadata, $doctrineModel, $reflectionClass, null);
        $idValue = $rowWrite->idValue;
        $table = $doctrineModel->getTableName();
        $insertColumnsAndValues = $this->renderInsertColumnsAndValues($rowWrite->columnWrites, $idValue);
        $values = $insertColumnsAndValues->values;
        $types = $insertColumnsAndValues->types;
        $sql = 'INSERT INTO "' . $table . '" (' . implode(', ', $insertColumnsAndValues->quotedColumns) . ') VALUES ('
            . implode(', ', $insertColumnsAndValues->valueExpressions) . ') RETURNING "id"';

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

    public function insert(
        DoctrineEntityManager $entityManager,
        DoctrineModel &$doctrineModel,
        ?QueryBuilder $createRightsQueryBuilder = null
    ): int {
        $connection = $entityManager->getConnection();
        $metadata = $entityManager->getClassMetadata($doctrineModel::class);
        $reflectionClass = ReflectionClass::instance($doctrineModel::class);
        $rowWrite = $this->buildColumnWrites($metadata, $doctrineModel, $reflectionClass, null);
        $idValue = $rowWrite->idValue;
        $table = $doctrineModel->getTableName();
        $insertColumnsAndValues = $this->renderInsertColumnsAndValues($rowWrite->columnWrites, $idValue);
        $values = $insertColumnsAndValues->values;
        $types = $insertColumnsAndValues->types;
        $sql = 'INSERT INTO "' . $table . '" (' . implode(', ', $insertColumnsAndValues->quotedColumns) . ') VALUES ('
            . implode(', ', $insertColumnsAndValues->valueExpressions) . ') RETURNING "id"';
        $insertRow = static function () use ($connection, $sql, $values, $types, $idValue, $table, &$doctrineModel): int {
            $row = $connection->fetchAssociative($sql, $values, $types);
            $entityId = (int)$row['id'];
            $doctrineModel->id = $entityId;
            if ($idValue !== null) {
                PostgresIdentityCatchUp::afterExplicitIdInsert($connection, $table, $entityId);
            }
            return $entityId;
        };

        // savepointed: a collision inside the caller's transaction reverts only this insert, so the caller can retry
        // in the same transaction (PostgreSQL would otherwise abort the whole transaction)
        if ($createRightsQueryBuilder === null) {
            return WriteExecutor::runSavepointed($connection, $insertRow);
        }
        return WriteExecutor::runInOwnOrCallerTransaction(
            $connection,
            function () use ($insertRow, &$doctrineModel, $createRightsQueryBuilder): int {
                $entityId = $insertRow();
                $this->checkInsertRightsOrThrow($doctrineModel, $createRightsQueryBuilder, $entityId);
                return $entityId;
            }
        );
    }

    // ───────────────────────────────────────────────────────── rights-scoped update (id + rights query, never inserts)

    protected function rightsScopedUpdate(
        DoctrineModel $doctrineModel,
        QueryBuilder $updateRightsQueryBuilder,
        ColumnWrites $columnWrites,
        Connection $connection
    ): int {
        $modelAlias = $doctrineModel::MODEL_ALIAS;
        $table = $doctrineModel->getTableName();

        $assignments = [];
        $values = [];
        $types = [];
        foreach ($columnWrites as $columnWrite) {
            $assignment = $this->renderUpdateByIdAssignment($columnWrite);
            $assignments[] = $assignment->sql;
            array_push($values, ...$assignment->values);
            array_push($types, ...$assignment->types);
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
     * The row's column writes (its initialized, non-identifier, non-virtual fields; only $onlyFieldNames when given)
     * and its explicit id.
     *
     * @param string[]|null $onlyFieldNames
     */
    protected function buildColumnWrites(
        ClassMetadata $metadata,
        DoctrineModel $doctrineModel,
        ReflectionClass $reflectionClass,
        ?array $onlyFieldNames
    ): RowWrite {
        $columns = new ColumnWrites();
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
            $columnWrite = $this->buildColumnWrite($metadata, $doctrineModel, $reflectionClass, $fieldName);
            $columns->add($columnWrite);
        }
        return new RowWrite($columns, $idValue);
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

    protected function findColumnWrite(ColumnWrites $columnWrites, string $columnName): ?ColumnWrite
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
     * @param ColumnWrites $columnWrites
     * @param string[][] $candidateKeys
     */
    protected function upsertWithNullableFallback(Connection $connection, string $table, ColumnWrites $columnWrites, array $candidateKeys): int
    {
        $upsertSql = $this->renderUpsertSql($table, $columnWrites, null, [], true);
        $row = $connection->fetchAssociative($upsertSql->sql, $upsertSql->values, $upsertSql->types);
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
                $assignment = $this->renderUpdateByIdAssignment($columnWrite);
                $assignments[] = $assignment->sql;
                array_push($updateValues, ...$assignment->values);
                array_push($updateTypes, ...$assignment->types);
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

    /** The SQL fragment for the column's `?` slot, the value to bind and whether a value is bound at all. */
    protected function valueExpressionFor(ColumnWrite $columnWrite): BindValueExpression
    {
        if ($columnWrite->kind === BindExpressionKind::GEOMETRY) {
            return $columnWrite->value !== null
                ? new BindValueExpression('ST_GeomFromText(?, 0)', $columnWrite->value, true)
                : new BindValueExpression('?', null, true);
        }
        if ($columnWrite->kind === BindExpressionKind::VECTOR) {
            if ($columnWrite->value !== null) {
                return new BindValueExpression('?::vector', $columnWrite->value, true);
            }
            if (($columnWrite->vectorDimensions ?? 0) > 0) {
                return new BindValueExpression($this->postgresNullVectorLiteral($columnWrite->vectorDimensions), null, false);
            }
            return new BindValueExpression('?', null, true);
        }
        return new BindValueExpression('?', $columnWrite->value, true);
    }

    protected function postgresNullVectorLiteral(int $dimensions): string
    {
        return "'[" . implode(',', array_fill(0, $dimensions, '0')) . "]'::vector";
    }

    protected function renderInsertColumnsAndValues(ColumnWrites $columnWrites, int|string|null $idValue): InsertColumnsAndValues
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
            $valueExpression = $this->valueExpressionFor($columnWrite);
            [$expr, $value, $bound] = [$valueExpression->sql, $valueExpression->value, $valueExpression->bindsValue];
            $columns[] = '"' . $columnWrite->columnName . '"';
            $placeholders[] = $expr;
            if ($bound) {
                $values[] = $value;
                $types[] = $columnWrite->dbalType;
            }
        }
        return new InsertColumnsAndValues($columns, $placeholders, $values, $types);
    }

    /**
     * @param string[] $conflictTargetColumns
     */
    protected function renderUpsertSql(
        string $table,
        ColumnWrites $columnWrites,
        int|string|null $idValue,
        array $conflictTargetColumns,
        bool $useInsertIgnore
    ): DoctrineBoundSql {
        $insertColumnsAndValues = $this->renderInsertColumnsAndValues($columnWrites, $idValue);
        $sql = 'INSERT INTO "' . $table . '" AS t (' . implode(', ', $insertColumnsAndValues->quotedColumns) . ') VALUES ('
            . implode(', ', $insertColumnsAndValues->valueExpressions) . ')';

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
        return new DoctrineBoundSql($sql, $insertColumnsAndValues->values, $insertColumnsAndValues->types);
    }

    protected function executeBulkRun(
        Connection $connection,
        string $table,
        array $modelIndexes,
        RowWrites $rowWrites,
        array $conflictTargetByIndex,
        bool $useInsertIgnore
    ): void {
        $rowWriteList = $rowWrites->getElements();
        $firstIndex = $modelIndexes[0];
        $columnWritesTemplate = $rowWriteList[$firstIndex]->columnWrites;
        $hasId = $rowWriteList[$firstIndex]->idValue !== null;
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
                $values[] = $rowWriteList[$index]->idValue;
                $types[] = 'integer';
            }
            foreach ($rowWriteList[$index]->columnWrites as $columnWrite) {
                $valueExpression = $this->valueExpressionFor($columnWrite);
                [$expr, $value, $bound] = [$valueExpression->sql, $valueExpression->value, $valueExpression->bindsValue];
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
                $columnWritesTemplate->getElements()
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

    /** The column's assignment in an `UPDATE … SET` with its bound values and types. */
    protected function renderUpdateByIdAssignment(ColumnWrite $columnWrite): DoctrineBoundSql
    {
        if ($columnWrite->updatePolicy === UpdatePolicy::RAW_ACTION) {
            return $this->renderRawActionForUpdateForm($columnWrite);
        }
        $valueExpression = $this->valueExpressionFor($columnWrite);
        [$expr, $value, $bound] = [$valueExpression->sql, $valueExpression->value, $valueExpression->bindsValue];
        $column = '"' . $columnWrite->columnName . '"';
        $values = $bound ? [$value] : [];
        $types = $bound ? [$columnWrite->dbalType] : [];
        if ($columnWrite->updatePolicy === UpdatePolicy::MERGE_PATCH) {
            return new DoctrineBoundSql("$column = ddd_json_merge_patch(COALESCE($column,'{}'), $expr)", $values, $types);
        }
        if ($columnWrite->updatePolicy === UpdatePolicy::KEEP_CREATED) {
            return new DoctrineBoundSql("$column = COALESCE($expr, $column)", $values, $types);
        }
        return new DoctrineBoundSql("$column = $expr", $values, $types);
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
     * @return DoctrineBoundSql
     */
    protected function renderRawActionForUpdateForm(ColumnWrite $columnWrite): DoctrineBoundSql
    {
        $values = [];
        $types = [];
        $valueExpression = $this->valueExpressionFor($columnWrite);
        [$expr, $value, $bound] = [$valueExpression->sql, $valueExpression->value, $valueExpression->bindsValue];
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
        return new DoctrineBoundSql('"' . $columnWrite->columnName . '" = ' . $translated, $values, $types);
    }
}
