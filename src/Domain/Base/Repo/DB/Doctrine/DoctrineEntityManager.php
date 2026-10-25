<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Dialect\DialectResolver;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\Common\Cache\Psr6\InvalidArgument;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\QueryBuilder;
use ReflectionException;

/** @noinspection PhpDocFinalChecksInspection */
class DoctrineEntityManager extends EntityManager
{
    // The interval in seconds to check if the connection is still pingable
    protected const int CONNECTION_CHECK_INTERVAL = 30;

    // The last time the connection was checked
    protected int $lastConnectionCheckTime = 0;

    /**
     * Monotonic per-process counter for the insert-rights savepoint names in {@see upsert()}. A savepoint name
     * must be unique per invocation: `SAVEPOINT <name>` with an already-used name silently REPLACES the old
     * mark, and upsert() nests (updateDependentEntities cascades into child upserts inside the same outer
     * transaction) — a reused name would make the outer check's ROLLBACK TO stop at the inner mark, leaving a
     * denied outer insert in place. A counter guarantees uniqueness structurally (savepoint scope is one
     * transaction on one connection = one process) and keeps names log-correlatable, unlike random suffixes.
     */
    protected static int $insertRightsSavepointCounter = 0;

    /**
     * Factory method to create EntityManager instances.
     *
     * @param mixed[]|Connection $connection An array with the connection parameters or an existing Connection instance.
     * @param Configuration $config The Configuration instance to use.
     * @param EventManager|null $eventManager The EventManager instance to use.
     * @psalm-param array<string, mixed>|Connection $connection
     *
     * @return EntityManager The created EntityManager.
     *
     * @throws InvalidArgument
     * @throws ORMException
     */
    public static function create($connection, Configuration $config, ?EventManager $eventManager = null)
    {
        $connection = static::createConnection($connection, $config, $eventManager);

        return new DoctrineEntityManager($connection, $config);
    }

    /**
     * {@inheritDoc}
     */
    public function createQueryBuilder(): DoctrineQueryBuilder
    {
        return new DoctrineQueryBuilder($this);
    }

    /**
     * D10's app-facing accessor (architecture plan §4.3 `$entityManager->getDialect()`), resolved from this
     * entity manager's OWN connection via {@see DialectResolver::forConnection()} — works for any entity manager
     * instance regardless of which scope (if any) it was created under, and needs no scope lookup.
     */
    public function getDialect(): SqlDialect
    {
        return DialectResolver::forConnection($this->getConnection());
    }

    /**
     * * Insert entity if it does not exist, update if it does.
     * ID is set to the enity after upsert.
     * Main reason to use this over fetch/save is to avoid race conditions.
     *
     * Warning: This method use DBAL, not ORM. It will save only the entity you send it.
     * It will NOT save the entity's associations. Entity manager won't know that the entity was flushed.
     * @param DoctrineModel $doctrineModel
     * @param QueryBuilder|null $updateRightsQueryBuilder
     * @return int|null
     * @throws Exception
     * @throws ForbiddenException
     * @throws ReflectionException
     */
    /**
     * @param string[]|null $onlyFieldNames When non-null, write STRICTLY the identifier + exactly these fields and
     *   nothing else — independent of which other model columns happen to be initialized (e.g. by the generated
     *   model's inline defaults). It still inserts when the row is missing; a strict partial write of an existing row
     *   is {@see self::updateColumnsById()}. Null = the normal full upsert (every initialized field).
     */
    public function upsert(DoctrineModel &$doctrineModel, ?QueryBuilder $updateRightsQueryBuilder = null, ?array $onlyFieldNames = null): ?int
    {
        // §6 step 3/4: PostgreSQL's write path (ON CONFLICT, not ON DUPLICATE KEY UPDATE) lives in its own
        // builder; everything below this branch is MariaDB's ORIGINAL code, untouched, so the golden suite
        // (byte-identical MariaDB SQL) stays valid by construction.
        if ($this->getDialect()->name() === SqlDialect::POSTGRES) {
            return $this->getDialect()->upsertBuilder()->upsert($this, $doctrineModel, $updateRightsQueryBuilder, $onlyFieldNames);
        }
        $connection = $this->getConnection();
        $metadata = $this->getClassMetadata($doctrineModel::class);
        $columns = [];
        $values = [];
        $types = [];
        $set = [];
        $update = [];
        $createdColumn = ChangeHistory::DEFAULT_CREATED_COLUMN_NAME;
        $reflectionClass = ReflectionClass::instance($doctrineModel::class);

        // Rights-scoped UPDATE path (id present + rights query): the write itself carries the rights condition
        // (`UPDATE … WHERE id = ? AND id IN (<rights query>)`) — ONE atomic statement instead of the former
        // read-then-write (a separate rights SELECT before the ODKU). That kills three problems at once: the
        // check-then-write race, the extra round-trip, and the snapshot conflict the SELECT caused as first
        // statement inside an outer transaction (MariaDB innodb_snapshot_isolation → error 1020 "Record has
        // changed since last read"). No transaction-state check needed — the statement is correct with and
        // without an outer transaction. The loop below builds the plain SET assignments for it in parallel to
        // the ODKU assignments.
        $rightsScopedUpdatePath = $updateRightsQueryBuilder && isset($doctrineModel->id) && $doctrineModel->id;
        $rightsScopedUpdateAssignments = [];
        $rightsScopedUpdateValues = [];
        $rightsScopedUpdateTypes = [];

        foreach ($metadata->getFieldNames() as $fieldName) {
            // We ignore virtual columns
            if (isset($doctrineModel::$virtualColumns[$fieldName])) {
                continue;
            }
            // STRICT partial write: with an explicit allow-list, write ONLY the identifier + the named fields. This is
            // independent of which other columns are initialized (the generated model's inline defaults would otherwise
            // be written and clobber a concurrent value), so the write touches strictly the desired columns.
            if ($onlyFieldNames !== null && !$metadata->isIdentifier($fieldName) && !in_array($fieldName, $onlyFieldNames, true)) {
                continue;
            }
            $value = $metadata->getFieldValue($doctrineModel, $fieldName);
            $fieldValue = $value;
            $column = $metadata->getColumnName($fieldName);
            $column = '`' . $column . '`';
            if ($metadata->isIdentifier($fieldName)) {
                if ($value !== null && $value) {
                } else {
                    // we need to set this explicitely, otherwise in case of updates we cannot access the id with lastInsertId();
                    $update[] = "$column = LAST_INSERT_ID($column)";
                    continue;
                }
            }
            $reflectionProperty = $reflectionClass->getProperty($fieldName);
            if (!$reflectionProperty->isInitialized($doctrineModel)) {
                continue;
            }
            /** @var DatabaseColumn $databaseColumnAttributeInstance */
            $databaseColumnAttributeInstance = $reflectionProperty->getAttributeInstance(DatabaseColumn::class);

            // A mergable JSON column may arrive here EITHER as a plain array (a Translatable-style column whose
            // mapToRepository returns the array directly) OR as a JSON-encoded STRING (a ValueObject column whose
            // mapToRepository returns an array that {@see \DDD\Domain\Base\Repo\DB\DBEntity::mapToRepository} then
            // json_encodes to a string before setting the model field). The JSON_MERGE_PATCH branch below is guarded on
            // is_array($value), so a string-valued mergable VO column would silently fall through to a plain VALUES()
            // REPLACE — clobbering concurrent per-key writers (e.g. N delegation children each merging one key). Decode
            // the string back to an array here so BOTH shapes take the same path: bound once via the JSON type AND
            // merged. Only touches a mergable column whose value is a valid JSON string; everything else is untouched.
            if ($databaseColumnAttributeInstance?->isMergableJSONColumn && is_string($value) && $value !== '') {
                $decodedMergableValue = json_decode($value, true);
                if (is_array($decodedMergableValue)) {
                    $value = $decodedMergableValue;
                }
            }

            $type = $metadata->getTypeOfField($fieldName);
            $skipValueBinding = false;
            // Handle Spatial Types
            if (isset(DatabaseColumn::SPATIAL_SQL_TYPES[$type])) {
                if ($value !== null) {
                    $set[] = 'ST_GeomFromText(?)';
                    $value = (string)$value;
                } else {
                    // Bind NULL directly — wrapping NULL in ST_GeomFromText is unnecessary
                    // and previously caused $types/$values misalignment for subsequent columns.
                    $set[] = '?';
                }
                $types[] = 'string';
            }
            elseif($type == 'vector'){
                if ($value !== null) {
                    $set[] = 'VEC_FromText(?)';
                    $value = json_encode($value, JSON_THROW_ON_ERROR);
                    $types[] = 'string';
                } else {
                    // VECTOR columns are generated NOT NULL with a zero-vector DB DEFAULT.
                    // Reuse the same SQL expression here so the bound row matches the column
                    // DEFAULT byte-for-byte and no multi-KB JSON string is materialized in PHP.
                    // Dimension comes from the ORM column's `length` argument (DatabaseModel
                    // generator), already available on the cached field mapping.
                    $fieldMapping = $metadata->fieldMappings[$fieldName] ?? null;
                    $dim = (int)($fieldMapping['length'] ?? 0);
                    if ($dim > 0) {
                        $set[] = (string)DatabaseColumn::createMariaDbNullVectorDefault($dim);
                        // SQL expression has no `?` — skip $values/$types push so placeholders
                        // stay aligned with bound parameters for subsequent columns.
                        $skipValueBinding = true;
                    } else {
                        // Unknown dimension — fall back to NULL bind (DB will reject if column
                        // is NOT NULL, but at least we don't silently misalign placeholders).
                        $set[] = '?';
                        $types[] = 'string';
                    }
                }
            }
            else {
                $set[] = '?';
                $types[] = $type;
            }
            $columns[] = $column;
            if (!$skipValueBinding) {
                $values[] = $value;
            }


            // Check if in column JSON contents should be merged with JSON_MERGE_PATCH
            if ($databaseColumnAttributeInstance?->isMergableJSONColumn && is_array(
                    $value
                )) {
                // If the column is marked for full replacement (e.g. replaceExistingTranslations), skip JSON_MERGE_PATCH
                if (in_array($fieldName, $doctrineModel->columnsToReplaceInsteadOfMerge, true)) {
                    $update[] = "$column = VALUES($column)";
                } else {
                    $update[] = "$column = JSON_MERGE_PATCH(COALESCE($column,'{}'), VALUES($column))";
                }
            } elseif ($fieldName == $createdColumn) {
                // we do not execute updates on created columns
                $update[] = "$column = COALESCE(VALUES($column), $column)";
            } elseif (isset($databaseColumnAttributeInstance->onUpdateAction)) {
                $update[] = "$column = " . $databaseColumnAttributeInstance->onUpdateAction;
            } else {
                $update[] = "$column = VALUES($column)";
            }

            // The rights-scoped direct UPDATE takes the same per-column semantics in UPDATE form (identifier columns
            // are skipped: the WHERE clause carries the id).
            if ($rightsScopedUpdatePath && !$metadata->isIdentifier($fieldName)) {
                $rightsScopedUpdateAssignment = $this->buildUpdateAssignmentForField(
                    $metadata,
                    $doctrineModel,
                    $fieldName,
                    $fieldValue,
                    $databaseColumnAttributeInstance
                );
                $rightsScopedUpdateAssignments[] = $rightsScopedUpdateAssignment->sql;
                array_push($rightsScopedUpdateValues, ...$rightsScopedUpdateAssignment->values);
                array_push($rightsScopedUpdateTypes, ...$rightsScopedUpdateAssignment->types);
            }
        }

        $modelAlias = $doctrineModel::MODEL_ALIAS;

        // Rights enforcement by operation:
        // - UPDATE path (id present): the rights condition is part of the WRITE statement itself
        //   (`UPDATE … WHERE id = ? AND id IN (<rights query>)`) — atomic, transaction-agnostic, no prior
        //   SELECT. 0 affected rows = the row is not writable under the rights query (or the values were
        //   identical — disambiguated below). Replaces the former read-then-write, whose SELECT (a) raced the
        //   write and (b) froze the snapshot as first statement inside an outer transaction (MariaDB
        //   innodb_snapshot_isolation → error 1020).
        // - INSERT path (no id): rights can only be verified AFTER inserting (the rights query needs the row),
        //   and a denial must roll the insert back. The revert mechanism depends on the transaction context:
        //   with no transaction open, the check OWNS a begin/commit; inside a foreign transaction it uses a
        //   SAVEPOINT (unique per invocation — see $insertRightsSavepointCounter) so a denial reverts ONLY the
        //   insert while the caller's transaction and its prior work stay intact. The old behavior — skipping
        //   the check entirely inside a foreign transaction — let an attacker insert rows into foreign scopes
        //   whenever any transaction happened to be open.
        if ($rightsScopedUpdatePath) {
            // Reduce the rights query to the visible ids and embed it as a derived table: the extra
            // `SELECT * FROM (…)` wrapper materializes it, which is what makes referencing the UPDATE's own
            // target table legal in MySQL/MariaDB (error 1093 otherwise).
            $rightsIdQueryBuilder = clone $updateRightsQueryBuilder;
            $rightsIdQueryBuilder->select("$modelAlias.id");
            $rightsIdQuery = $rightsIdQueryBuilder->getQuery();
            // getSQL() is the stable public way to the generated SQL (ORM ≥2.20 fills the ParserResult via the
            // SqlFinalizer path, so ParserResult::getSqlExecutor() can be null); the Parser run below is still
            // needed for the named-DQL-parameter → positional-SQL mapping, which has no public accessor on Query.
            $rightsSelectSql = $rightsIdQuery->getSQL();
            $rightsParserResult = (new Parser($rightsIdQuery))->parse();
            // Flatten the DQL named parameters into SQL positional order via the parser's mapping.
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

            $rightsScopedAffectedRows = 0;
            if ($rightsScopedUpdateAssignments !== []) {
                $sql = 'UPDATE ' . $doctrineModel->getTableName()
                    . ' SET ' . implode(', ', $rightsScopedUpdateAssignments)
                    . ' WHERE `id` = ? AND `id` IN (SELECT * FROM (' . $rightsSelectSql . ') dddUpdateRightsScope)';
                $rightsScopedAffectedRows = (int)$connection->executeStatement(
                    $sql,
                    [...$rightsScopedUpdateValues, $doctrineModel->id, ...$rightsParameterValues],
                    [...$rightsScopedUpdateTypes, 'integer', ...$rightsParameterTypes]
                );
            }
            if ($rightsScopedAffectedRows === 0) {
                // MySQL reports CHANGED rows (not matched rows), so 0 also covers a PERMITTED update whose
                // values were all identical (idempotent save) — and the no-assignment edge (only the id was
                // initialized). ONE follow-up rights SELECT on this rare path separates "no-op, permitted"
                // from "row not writable under rights" — the hot path stays single-statement.
                $checkRightsQueryBuilder = clone $updateRightsQueryBuilder;
                $checkRightsQueryBuilder->andWhere("$modelAlias.id = :entityId");
                $checkRightsQueryBuilder->setParameter('entityId', $doctrineModel->id);
                $rowVisibleUnderRightsQuery = $checkRightsQueryBuilder->getQuery()->setMaxResults(1)->getResult()[0] ?? null;
                if (!$rowVisibleUnderRightsQuery) {
                    $authAccount = AuthService::instance()->getAccount() ?? null;
                    $authAccountId = $authAccount ? $authAccount->id : '(not logged in)';
                    throw new ForbiddenException(
                        'Account ' . $authAccountId . ' has no permission to update Entity ' . $doctrineModel::ENTITY_CLASS . ' with id ' . $doctrineModel->id
                    );
                }
            }
            return (int)$doctrineModel->id;
        }

        $checkInsertRights = $updateRightsQueryBuilder && !isset($doctrineModel->id);
        $checkInsertRightsInOwnTransaction = false;
        $insertRightsSavepointName = null;
        // we need a revert point to be able to roll the insert back if the post-insert rights check denies:
        // an own transaction when none is open, a savepoint inside a caller's transaction
        if ($checkInsertRights) {
            if (!$connection->isTransactionActive()) {
                $checkInsertRightsInOwnTransaction = true;
                $connection->beginTransaction();
            } else {
                $insertRightsSavepointName = 'DDD_UPSERT_INSERT_RIGHTS_' . ++self::$insertRightsSavepointCounter;
                $connection->createSavepoint($insertRightsSavepointName);
            }
        }
        $sql = 'INSERT INTO ' . $doctrineModel->getTableName() . ' (' . implode(
                ', ',
                $columns
            ) . ')' . ' VALUES (' . implode(
                ', ',
                $set
            ) . ')' . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $update);
        // EVERYTHING between our beginTransaction() and its commit() lives inside this try — the insert itself,
        // lastInsertId, and the whole post-insert rights re-check. Before this, the rights re-check query ran
        // OUTSIDE any try: a throw there (rights-join lock timeout, connection loss, schema drift) leaked the
        // open transaction into the long-lived worker connection — silently disabling all subsequent rights
        // checks and collecting every later write into a never-committed transaction (lost on worker death).
        try {
            $connection->executeStatement($sql, $values, $types);
            $entityId = $doctrineModel->id ?? (int)$connection->lastInsertId();

            // INSERT rights check: the freshly inserted row must be visible under the rights query — otherwise
            // deny; the catch below reverts the insert (own-transaction rollback or savepoint rollback).
            if ($checkInsertRights) {
                $checkRightsQueryBuilder = clone $updateRightsQueryBuilder;
                $checkRightsQueryBuilder->andWhere("$modelAlias.id = :entityId");
                $checkRightsQueryBuilder->setParameter('entityId', $entityId);

                $loadedOrmInstanceWithUpdateRightsQueryApplied = $checkRightsQueryBuilder->getQuery()->setMaxResults(
                    1
                )->getResult();
                $loadedOrmInstanceWithUpdateRightsQueryApplied = $loadedOrmInstanceWithUpdateRightsQueryApplied[0] ?? null;

                if (!$loadedOrmInstanceWithUpdateRightsQueryApplied) {
                    $authAccount = AuthService::instance()->getAccount() ?? null;
                    $authAccountId = $authAccount ? $authAccount->id : '(not logged in)';
                    throw new ForbiddenException(
                        'Account ' . $authAccountId . ' has no permission to update or create Entity ' . $doctrineModel::ENTITY_CLASS
                    );
                }
                if ($checkInsertRightsInOwnTransaction) {
                    $connection->commit();
                } elseif ($insertRightsSavepointName !== null) {
                    // permitted: drop the mark — the insert becomes part of the caller's transaction and
                    // commits (or dies) with it
                    $connection->releaseSavepoint($insertRightsSavepointName);
                }
            }
        } catch (\Throwable $t) {
            // Revert ONLY what WE opened above — NEVER a caller's transaction (the DB_USE_READ_AFTER_WRITE
            // wrapper in DatabaseRepoEntity::update(), or an application-level transaction around update()).
            if ($checkInsertRightsInOwnTransaction) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                } else {
                    // commit() itself threw: DBAL has already zeroed its nesting counter (finally in
                    // Connection::commit), so no rollBack() can reach the driver anymore — close() clears a
                    // possibly orphaned server-side transaction; the connection reconnects on next use.
                    $connection->close();
                }
            } elseif ($insertRightsSavepointName !== null && $connection->isTransactionActive()) {
                try {
                    // undoes ONLY the insert; the caller's transaction and its prior work stay intact
                    $connection->rollbackSavepoint($insertRightsSavepointName);
                } catch (\Throwable) {
                    // the caller's transaction is broken beyond savepoint reach (e.g. connection loss) — the
                    // original exception carries the cause; the caller's own rollback / the central desync
                    // heal covers the connection state
                }
            }
            throw $t;
        }
        return $entityId;
    }

    /**
     * "Insert or lose the race" (architecture plan §4.3): a plain INSERT that returns null on a unique-key
     * collision instead of merging onto the row that won the race — the facade method dialect-dispatches to
     * {@see \DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertBuilder::insertOrNull()}; MariaDB's implementation is new
     * (nothing existing does this), PostgreSQL's catches a 23505 via `ON CONFLICT` having no target to land on.
     * @throws Exception
     * @throws MappingException
     * @throws ReflectionException
     */
    public function insertOrNull(DoctrineModel &$doctrineModel): ?int
    {
        return $this->getDialect()->upsertBuilder()->insertOrNull($this, $doctrineModel);
    }

    /**
     * Strict partial write of an EXISTING row: `UPDATE <table> SET <the named, initialized fields> WHERE id = ?`. There
     * is no insert branch — a row deleted in the meantime stays deleted (the former upsert form inserted a ghost row
     * holding only these columns). Each column takes the UPDATE form the rights-scoped write uses
     * ({@see self::buildUpdateAssignmentForField()}).
     *
     * MariaDB counts CHANGED rows, so 0 affected rows is followed by `SELECT 1 … WHERE id = ?` to tell "missing"
     * (null) from "unchanged" (the id). Without any assignment only that check runs.
     *
     * @param DoctrineModel $doctrineModel Model carrying the id and the fields to write
     * @param string[] $fieldNames The model fields to write; others are never touched
     * @return int|null The id, or null when the row does not exist (nothing is written)
     * @throws Exception
     * @throws MappingException
     * @throws ReflectionException
     */
    public function updateColumnsById(DoctrineModel &$doctrineModel, array $fieldNames): ?int
    {
        if ($this->getDialect()->name() === SqlDialect::POSTGRES) {
            return $this->getDialect()->upsertBuilder()->updateColumnsById($this, $doctrineModel, $fieldNames);
        }
        if (!isset($doctrineModel->id) || !$doctrineModel->id) {
            return null;
        }
        $connection = $this->getConnection();
        $metadata = $this->getClassMetadata($doctrineModel::class);
        $reflectionClass = ReflectionClass::instance($doctrineModel::class);
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
            $assignment = $this->buildUpdateAssignmentForField(
                $metadata,
                $doctrineModel,
                $fieldName,
                $metadata->getFieldValue($doctrineModel, $fieldName),
                $reflectionProperty->getAttributeInstance(DatabaseColumn::class)
            );
            $assignments[] = $assignment->sql;
            array_push($values, ...$assignment->values);
            array_push($types, ...$assignment->types);
        }
        $entityId = (int)$doctrineModel->id;
        if ($assignments !== []) {
            $affectedRows = (int)$connection->executeStatement(
                'UPDATE ' . $doctrineModel->getTableName() . ' SET ' . implode(', ', $assignments) . ' WHERE `id` = ?',
                [...$values, $entityId],
                [...$types, 'integer']
            );
            if ($affectedRows > 0) {
                return $entityId;
            }
        }
        $rowExists = $connection->fetchOne(
            'SELECT 1 FROM ' . $doctrineModel->getTableName() . ' WHERE `id` = ?',
            [$entityId],
            ['integer']
        ) !== false;
        return $rowExists ? $entityId : null;
    }

    /**
     * One column's assignment in UPDATE form (`col = …`), shared by the rights-scoped write in {@see self::upsert()} and
     * {@see self::updateColumnsById()}:
     *
     * - mergable JSON (array value): `JSON_MERGE_PATCH(COALESCE(col,'{}'), ?)`, or `col = ?` for a column in
     *   `columnsToReplaceInsteadOfMerge`; a JSON string value is decoded first, as upsert() does;
     * - the created column: `COALESCE(?, col)` — never overwritten;
     * - spatial: `ST_GeomFromText(?)` (NULL bound plainly); vector: `VEC_FromText(?)`, or the zero vector for NULL;
     * - an `onUpdateAction`: the action, with `VALUES(<own column>)` replaced by the bound value — `VALUES()` is NULL
     *   outside INSERT … ON DUPLICATE KEY UPDATE, so a verbatim copy would drop the incoming value; `VALUES()` of any
     *   other column has no value here and is refused; an action without `VALUES()` (a keep-existing form) is copied
     *   and binds nothing.
     *
     * @param ClassMetadata $metadata Metadata of the model class
     * @param DoctrineModel $doctrineModel The model
     * @param string $fieldName Model field
     * @param mixed $value The field value as read from the model
     * @param DatabaseColumn|null $databaseColumnAttributeInstance The field's DatabaseColumn attribute
     * @return DoctrineBoundSql The assignment and its values
     * @throws MappingException
     * @throws \LogicException When an onUpdateAction reads VALUES() of another column
     */
    protected function buildUpdateAssignmentForField(
        ClassMetadata $metadata,
        DoctrineModel $doctrineModel,
        string $fieldName,
        mixed $value,
        ?DatabaseColumn $databaseColumnAttributeInstance
    ): DoctrineBoundSql {
        $columnName = $metadata->getColumnName($fieldName);
        $column = '`' . $columnName . '`';
        $type = $metadata->getTypeOfField($fieldName);

        if ($databaseColumnAttributeInstance?->isMergableJSONColumn && is_string($value) && $value !== '') {
            $decodedMergableValue = json_decode($value, true);
            if (is_array($decodedMergableValue)) {
                $value = $decodedMergableValue;
            }
        }

        if (isset(DatabaseColumn::SPATIAL_SQL_TYPES[$type])) {
            $valueExpression = $value !== null ? new DoctrineBoundSql('ST_GeomFromText(?)', [(string)$value], ['string'])
                : new DoctrineBoundSql('?', [null], ['string']);
        } elseif ($type == 'vector') {
            $vectorDimensions = (int)($metadata->fieldMappings[$fieldName]['length'] ?? 0);
            if ($value !== null) {
                $valueExpression = new DoctrineBoundSql('VEC_FromText(?)', [json_encode($value, JSON_THROW_ON_ERROR)], ['string']);
            } elseif ($vectorDimensions > 0) {
                $valueExpression = new DoctrineBoundSql((string)DatabaseColumn::createMariaDbNullVectorDefault($vectorDimensions));
            } else {
                $valueExpression = new DoctrineBoundSql('?', [null], ['string']);
            }
        } else {
            $valueExpression = new DoctrineBoundSql('?', [$value], [$type]);
        }

        if ($databaseColumnAttributeInstance?->isMergableJSONColumn && is_array($value)) {
            if (in_array($fieldName, $doctrineModel->columnsToReplaceInsteadOfMerge, true)) {
                return new DoctrineBoundSql("$column = $valueExpression->sql", $valueExpression->values, $valueExpression->types);
            }
            return new DoctrineBoundSql(
                "$column = JSON_MERGE_PATCH(COALESCE($column,'{}'), $valueExpression->sql)",
                $valueExpression->values,
                $valueExpression->types
            );
        }
        if ($fieldName == ChangeHistory::DEFAULT_CREATED_COLUMN_NAME) {
            return new DoctrineBoundSql("$column = COALESCE($valueExpression->sql, $column)", $valueExpression->values, $valueExpression->types);
        }
        if (isset($databaseColumnAttributeInstance->onUpdateAction)) {
            return $this->buildOnUpdateActionAssignment(
                $column,
                $columnName,
                $databaseColumnAttributeInstance->onUpdateAction,
                $valueExpression
            );
        }
        return new DoctrineBoundSql("$column = $valueExpression->sql", $valueExpression->values, $valueExpression->types);
    }

    /**
     * An onUpdateAction in UPDATE form: every `VALUES(<own column>)` becomes the bound value expression (bound once per
     * occurrence, in textual order, so the placeholders stay aligned).
     *
     * @param string $column The quoted column
     * @param string $columnName The column name
     * @param string $onUpdateAction The action as declared, written for ON DUPLICATE KEY UPDATE
     * @param DoctrineBoundSql $valueExpression The value expression of this column
     * @return DoctrineBoundSql
     * @throws \LogicException When the action reads VALUES() of another column
     */
    protected function buildOnUpdateActionAssignment(
        string $column,
        string $columnName,
        string $onUpdateAction,
        DoctrineBoundSql $valueExpression
    ): DoctrineBoundSql {
        $values = [];
        $types = [];
        $actionInUpdateForm = preg_replace_callback(
            '/\bVALUES\s*\(\s*`?([A-Za-z0-9_]+)`?\s*\)/i',
            function (array $valuesCall) use ($columnName, $onUpdateAction, $valueExpression, &$values, &$types): string {
                if ($valuesCall[1] !== $columnName) {
                    throw new \LogicException(
                        "onUpdateAction of column `$columnName` reads VALUES(`{$valuesCall[1]}`), which has no value in an UPDATE: $onUpdateAction"
                    );
                }
                array_push($values, ...$valueExpression->values);
                array_push($types, ...$valueExpression->types);
                return '(' . $valueExpression->sql . ')';
            },
            $onUpdateAction
        );
        return new DoctrineBoundSql("$column = $actionInUpdateForm", $values, $types);
    }

    /**
     * Writes many models of one class with multi-row INSERT … ON DUPLICATE KEY UPDATE statements
     * ({@see self::buildUpsertMultipleStatements()}). Several statements run atomically: in an own transaction when
     * none is open, behind a savepoint inside a caller's transaction (so a caller that catches keeps none of the
     * batch). A single statement takes neither.
     *
     * @param DoctrineModel[] $doctrineModels Models of one class
     * @param bool $useInsertIgnore INSERT IGNORE without ON DUPLICATE KEY UPDATE
     * @return void
     * @throws Exception
     * @throws MappingException
     * @throws ReflectionException
     */
    public function upsertMultiple(array &$doctrineModels, bool $useInsertIgnore = false): void
    {
        if ($this->getDialect()->name() === SqlDialect::POSTGRES) {
            $this->getDialect()->upsertBuilder()->upsertMultiple($this, $doctrineModels, $useInsertIgnore);
            return;
        }
        $statements = $this->buildUpsertMultipleStatements($doctrineModels, $useInsertIgnore);
        if (!$statements->count()) {
            return;
        }
        $connection = $this->getConnection();
        if ($statements->count() === 1) {
            $statement = $statements->first();
            $connection->executeStatement($statement->sql, $statement->values, $statement->types);
            return;
        }
        $ownTransaction = !$connection->isTransactionActive();
        $savepointName = null;
        if ($ownTransaction) {
            $connection->beginTransaction();
        } else {
            $savepointName = 'DDD_UPSERT_MULTIPLE_' . ++self::$insertRightsSavepointCounter;
            $connection->createSavepoint($savepointName);
        }
        try {
            foreach ($statements->getElements() as $statement) {
                $connection->executeStatement($statement->sql, $statement->values, $statement->types);
            }
            if ($ownTransaction) {
                $connection->commit();
            } else {
                $connection->releaseSavepoint($savepointName);
            }
        } catch (\Throwable $throwable) {
            if ($ownTransaction) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            } elseif ($connection->isTransactionActive()) {
                try {
                    $connection->rollbackSavepoint($savepointName);
                } catch (\Throwable) {
                    // the caller's transaction is broken beyond savepoint reach; the original exception carries the cause
                }
            }
            throw $throwable;
        }
    }

    /**
     * Builds the statements of a batch upsert. Each row writes only its INITIALIZED fields (as {@see self::upsert()}
     * does — an uninitialized field, e.g. a #[PreserveOnOmission] property the payload omitted, keeps its stored value),
     * and a row without an id leaves the id column out. Rows are grouped into contiguous runs, in input order, of the
     * same shape (id present, initialized fields, columns to replace instead of merge); a uniform batch is one
     * statement. The ON DUPLICATE KEY UPDATE list of a run names only that run's columns: `VALUES(c)` of a column
     * absent from the INSERT list is NULL and would overwrite the stored value.
     *
     * Column forms: spatial `ST_GeomFromText(?)` (NULL bound plainly, always one bound value), vector
     * `VEC_FromText(?)` or the zero vector for NULL, a mergable JSON string decoded like upsert() does; on a duplicate
     * key: created `COALESCE(VALUES(c), c)`, mergable JSON `JSON_MERGE_PATCH(COALESCE(c,'{}'), COALESCE(VALUES(c),'{}'))`
     * (a NULL keeps the stored value) or `VALUES(c)` for a replace column, an onUpdateAction verbatim, else `VALUES(c)`.
     *
     * @param DoctrineModel[] $doctrineModels Models of one class
     * @param bool $useInsertIgnore INSERT IGNORE without ON DUPLICATE KEY UPDATE
     * @return DoctrineBoundSqls The statements, in execution order
     * @throws MappingException
     * @throws ReflectionException
     */
    public function buildUpsertMultipleStatements(array $doctrineModels, bool $useInsertIgnore = false): DoctrineBoundSqls
    {
        $statements = new DoctrineBoundSqls();
        if (!count($doctrineModels)) {
            return $statements;
        }
        $firstModel = reset($doctrineModels);
        $metadata = $this->getClassMetadata($firstModel::class);
        $reflectionClass = ReflectionClass::instance($firstModel::class);
        $tableName = $firstModel->getTableName();

        // per field, once: reflection, attribute, type, quoted column
        $fieldDefinitions = [];
        foreach ($metadata->getFieldNames() as $fieldName) {
            if (isset($firstModel::$virtualColumns[$fieldName])) {
                continue;
            }
            $reflectionProperty = $reflectionClass->getProperty($fieldName);
            $fieldDefinitions[$fieldName] = [
                'reflectionProperty' => $reflectionProperty,
                'databaseColumn' => $reflectionProperty->getAttributeInstance(DatabaseColumn::class),
                'type' => $metadata->getTypeOfField($fieldName),
                'column' => '`' . $metadata->getColumnName($fieldName) . '`',
                'isIdentifier' => $metadata->isIdentifier($fieldName),
                'vectorDimensions' => (int)($metadata->fieldMappings[$fieldName]['length'] ?? 0),
            ];
        }

        // contiguous runs of rows with the same shape
        $runs = [];
        $currentRunKey = null;
        foreach ($doctrineModels as $doctrineModel) {
            $rowFieldNames = [];
            foreach ($fieldDefinitions as $fieldName => $fieldDefinition) {
                if (!$fieldDefinition['reflectionProperty']->isInitialized($doctrineModel)) {
                    continue;
                }
                if ($fieldDefinition['isIdentifier'] && !$metadata->getFieldValue($doctrineModel, $fieldName)) {
                    continue;
                }
                $rowFieldNames[] = $fieldName;
            }
            $columnsToReplace = array_values(array_intersect($doctrineModel->columnsToReplaceInsteadOfMerge, $rowFieldNames));
            sort($columnsToReplace);
            $runKey = implode(',', $rowFieldNames) . '|' . implode(',', $columnsToReplace);
            if ($runKey !== $currentRunKey) {
                $runs[] = ['fieldNames' => $rowFieldNames, 'columnsToReplace' => $columnsToReplace, 'models' => []];
                $currentRunKey = $runKey;
            }
            $runs[array_key_last($runs)]['models'][] = $doctrineModel;
        }

        foreach ($runs as $run) {
            if ($run['fieldNames'] === []) {
                continue;
            }
            $columns = [];
            $updateAssignments = [];
            $identifierUpdated = false;
            foreach ($run['fieldNames'] as $fieldName) {
                $fieldDefinition = $fieldDefinitions[$fieldName];
                $column = $fieldDefinition['column'];
                $columns[] = $column;
                if ($fieldName == ChangeHistory::DEFAULT_CREATED_COLUMN_NAME) {
                    $updateAssignments[] = "$column = COALESCE(VALUES($column), $column)";
                } elseif ($fieldDefinition['isIdentifier']) {
                    // makes lastInsertId() return the id of an updated row too
                    $updateAssignments[] = "$column = LAST_INSERT_ID($column)";
                    $identifierUpdated = true;
                } elseif ($fieldDefinition['databaseColumn']?->isMergableJSONColumn) {
                    $updateAssignments[] = in_array($fieldName, $run['columnsToReplace'], true)
                        ? "$column = VALUES($column)"
                        : "$column = JSON_MERGE_PATCH(COALESCE($column,'{}'), COALESCE(VALUES($column),'{}'))";
                } elseif (isset($fieldDefinition['databaseColumn']->onUpdateAction)) {
                    $updateAssignments[] = "$column = " . $fieldDefinition['databaseColumn']->onUpdateAction;
                } else {
                    $updateAssignments[] = "$column = VALUES($column)";
                }
            }
            if (!$identifierUpdated) {
                foreach ($fieldDefinitions as $fieldDefinition) {
                    if ($fieldDefinition['isIdentifier']) {
                        $updateAssignments[] = "{$fieldDefinition['column']} = LAST_INSERT_ID({$fieldDefinition['column']})";
                    }
                }
            }

            $rowPlaceholders = [];
            $values = [];
            $types = [];
            foreach ($run['models'] as $doctrineModel) {
                $rowExpressions = [];
                foreach ($run['fieldNames'] as $fieldName) {
                    $fieldDefinition = $fieldDefinitions[$fieldName];
                    $type = $fieldDefinition['type'];
                    $value = $metadata->getFieldValue($doctrineModel, $fieldName);
                    if (isset(DatabaseColumn::SPATIAL_SQL_TYPES[$type])) {
                        $rowExpressions[] = $value !== null ? 'ST_GeomFromText(?)' : '?';
                        $values[] = $value !== null ? (string)$value : null;
                        $types[] = 'string';
                    } elseif ($type == 'vector') {
                        if ($value !== null) {
                            $rowExpressions[] = 'VEC_FromText(?)';
                            $values[] = json_encode($value, JSON_THROW_ON_ERROR);
                            $types[] = 'string';
                        } elseif ($fieldDefinition['vectorDimensions'] > 0) {
                            $rowExpressions[] = (string)DatabaseColumn::createMariaDbNullVectorDefault($fieldDefinition['vectorDimensions']);
                        } else {
                            $rowExpressions[] = '?';
                            $values[] = null;
                            $types[] = 'string';
                        }
                    } else {
                        if ($fieldDefinition['databaseColumn']?->isMergableJSONColumn && is_string($value) && $value !== '') {
                            $decodedMergableValue = json_decode($value, true);
                            if (is_array($decodedMergableValue)) {
                                $value = $decodedMergableValue;
                            }
                        }
                        $rowExpressions[] = '?';
                        $values[] = $value;
                        $types[] = $type;
                    }
                }
                $rowPlaceholders[] = '(' . implode(', ', $rowExpressions) . ')';
            }
            $sql = 'INSERT ' . ($useInsertIgnore ? 'IGNORE ' : '') . 'INTO ' . $tableName
                . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $rowPlaceholders);
            if (!$useInsertIgnore) {
                $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateAssignments);
            }
            $statement = new DoctrineBoundSql($sql, $values, $types);
            $statements->add($statement);
        }
        return $statements;
    }

    public function createQuery($dql = ''): DoctrineQuery
    {
        $query = new DoctrineQuery($this);

        if (!empty($dql)) {
            $query->setDQL($dql);
        }

        return $query;
    }

    /**
     * Verify the connection to the database.
     * @return bool Returns true if the connection is active, false otherwise.
     */
    public function isConnectionActive(): bool
    {
        // Check if the connection is still pingable every CONNECTION_CHECK_INTERVAL seconds in case of a connection loss
        if (time() - $this->lastConnectionCheckTime < self::CONNECTION_CHECK_INTERVAL) {
            return true;
        }

        try {
            $this->getConnection()->executeQuery('SELECT 1');
            $this->lastConnectionCheckTime = time();
            return true;
        } catch (ConnectionLost|Exception) {
            return false;
        }
    }

    /**
     * Heals a transaction-state desync between DBAL's nesting counter and the native PDO connection.
     *
     * A driver-level COMMIT/ROLLBACK failure leaves DBAL's nesting counter at 0 (Connection::commit() decrements
     * in a finally; rollBack() zeroes the counter BEFORE the driver call) while the server-side transaction can
     * survive as an orphan — pdo_mysql's inTransaction() then keeps reporting true from the server status of
     * every following OK packet. In that state every later beginTransaction() throws PDO "There is already an
     * active transaction", and no rollBack() can ever reach the driver again (at counter 0 DBAL throws
     * NoActiveTransaction). The reverse mismatch (counter > 0 with no server transaction — a failed driver BEGIN
     * leaves the counter incremented, there is no try/finally around it) silently disables the upsert rights
     * checks and degrades FOR UPDATE semantics to autocommit. Both directions are unhealable through the DBAL
     * API; both hit long-lived Messenger workers, where the poisoned connection survives across messages.
     *
     * Detection compares the native PDO in-transaction status against DBAL's counter. pdo_mysql's flag reflects
     * the server status of the LAST received OK/EOF packet and can lag one statement, so a mismatch is first
     * re-checked after a refreshing SELECT 1 — only a persistent mismatch is healed. Heal = Connection::close():
     * it zeroes the nesting counter and drops the PDO (the server rolls an orphaned transaction back on
     * disconnect — it was uncommittable anyway, commit() at counter 0 throws), and the connection auto-reconnects
     * on next use. Healing in place keeps method-local $connection references valid, unlike recreating the
     * EntityManager.
     *
     * Called unthrottled from EntityManagerFactory::getInstance() — on the consistent (normal) path this is a
     * client-side flag comparison with no server round-trip, so it must NOT sit behind the
     * CONNECTION_CHECK_INTERVAL throttle (and the SELECT-1 ping cannot detect this state anyway: it succeeds on
     * a desynced connection).
     */
    public function healDesyncedTransactionState(): void
    {
        $connection = $this->getConnection();
        // Never force a connect just to inspect state — getNativeConnection() connects eagerly.
        if (!$connection->isConnected()) {
            return;
        }
        try {
            $nativeConnection = $connection->getNativeConnection();
        } catch (\Throwable) {
            return;
        }
        if (!$nativeConnection instanceof \PDO) {
            return;
        }
        if ($nativeConnection->inTransaction() === $connection->isTransactionActive()) {
            return;
        }
        // The native flag can lag one statement behind the server; a successful query refreshes it, so a
        // merely-stale mismatch dissolves here without dropping the connection.
        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            // Query failure on a mismatched connection — fall through to close(), reconnect covers this too.
        }
        if ($nativeConnection->inTransaction() === $connection->isTransactionActive()) {
            return;
        }
        DDDService::instance()->getLogger()->warning(
            sprintf(
                'DoctrineEntityManager: healed desynced transaction state (DBAL nesting level %d, native inTransaction %s) by closing the connection',
                $connection->getTransactionNestingLevel(),
                $nativeConnection->inTransaction() ? 'true' : 'false'
            )
        );
        $connection->close();
    }
}
