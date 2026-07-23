<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\Common\Cache\Psr6\InvalidArgument;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Mapping\MappingException;
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
     *   model's inline defaults). This is the strict-partial-write primitive behind
     *   {@see \DDD\Domain\Base\Repo\DB\DBEntity::updatePartialIgnoringRights()}. Null = the normal full upsert (every
     *   initialized field).
     */
    public function upsert(DoctrineModel &$doctrineModel, ?QueryBuilder $updateRightsQueryBuilder = null, ?array $onlyFieldNames = null): ?int
    {
        $connection = $this->getConnection();
        $metadata = $this->getClassMetadata($doctrineModel::class);
        $columns = [];
        $values = [];
        $types = [];
        $set = [];
        $update = [];
        $hasId = $metadata->containsForeignIdentifier;
        $createdColumn = ChangeHistory::DEFAULT_CREATED_COLUMN_NAME;
        $reflectionClass = ReflectionClass::instance($doctrineModel::class);

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
            $column = $metadata->getColumnName($fieldName);
            $column = '`' . $column . '`';
            if ($metadata->isIdentifier($fieldName)) {
                if ($value !== null && $value) {
                    $hasId = true;
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
        }

        $modelAlias = $doctrineModel::MODEL_ALIAS;

        // Rights enforcement is split by operation:
        // - UPDATE path (id present): a plain rights-scoped SELECT — it needs no transaction, so it runs
        //   REGARDLESS of an already-active (outer) transaction. The old single gate
        //   (!isTransactionActive() && $updateRightsQueryBuilder) made every update executed inside ANY outer
        //   transaction (e.g. the DB_USE_READ_AFTER_WRITE wrapper or an application-level transaction) skip
        //   row-scoped rights entirely — with rights active, upsert() is the ONLY row-scope enforcer for writes.
        // - INSERT path (no id): rights can only be verified AFTER inserting (the rights query needs the row),
        //   and a denial must roll the insert back — so this check must OWN a transaction and stays gated on
        //   transaction-free entry (inside a foreign transaction the insert runs unchecked, as before; making
        //   it nest safely would require savepoints).
        $checkUpdateRightsOnExistingId = $updateRightsQueryBuilder && isset($doctrineModel->id);
        $checkInsertRightsInOwnTransaction = $updateRightsQueryBuilder && !isset($doctrineModel->id)
            && !$connection->isTransactionActive();

        if ($checkUpdateRightsOnExistingId) {
            $checkRightsQueryBuilder = clone $updateRightsQueryBuilder;
            $checkRightsQueryBuilder->andWhere("$modelAlias.id = :entityId");
            $checkRightsQueryBuilder->setParameter('entityId', $doctrineModel->id);
            $loadedOrmInstanceWithUpdateRightsQueryApplied = $checkRightsQueryBuilder->getQuery()->setMaxResults(
                1
            )->getResult();
            $loadedOrmInstanceWithUpdateRightsQueryApplied = $loadedOrmInstanceWithUpdateRightsQueryApplied[0] ?? null;
            if (!$loadedOrmInstanceWithUpdateRightsQueryApplied) {
                $authAccount = AuthService::instance()->getAccount() ?? null;
                $authAccountId = $authAccount ? $authAccount->id : '(not logged in)';
                throw new ForbiddenException(
                    'Account ' . $authAccountId . ' has no permission to update Entity ' . $doctrineModel::ENTITY_CLASS . ' with id ' . $doctrineModel->id
                );
            }
        }
        // we need to start a transaction to be able to roll the insert back if the post-insert rights check denies
        if ($checkInsertRightsInOwnTransaction) {
            $connection->beginTransaction();
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
            // deny; the catch below rolls the insert back.
            if ($checkInsertRightsInOwnTransaction) {
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
                $connection->commit();
            }
        } catch (\Throwable $t) {
            // Roll back ONLY the transaction WE began above — NEVER a caller's (the DB_USE_READ_AFTER_WRITE
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
            }
            throw $t;
        }
        return $entityId;
    }

    /**
     * Upsert multiple entities with JSON_MERGE_PATCH for specified JSON mergeable columns.
     *
     * @param DoctrineModel[] $doctrineModels
     * @param bool $useInsertIgnore
     * @return void
     * @throws Exception
     * @throws MappingException
     */
    public function upsertMultiple(array &$doctrineModels, bool $useInsertIgnore = false): void
    {
        if (!count($doctrineModels)) {
            return;
        }
        $connection = $this->getConnection();
        $firstModel = reset($doctrineModels);
        $metadata = $this->getClassMetadata(reset($doctrineModels)::class);
        $identifier = $metadata->getSingleIdentifierFieldName();

        $columns = [];
        $values = [];
        $types = [];
        $set = [];
        $update = [];
        $hasId = $metadata->containsForeignIdentifier;
        $createdColumn = ChangeHistory::DEFAULT_CREATED_COLUMN_NAME;
        $doctrineModel = $doctrineModels[0];
        $reflectionClass = ReflectionClass::instance($firstModel::class);

        // Get column names outside of the loop
        foreach ($metadata->getFieldNames() as $fieldName) {
            // We ignore virtual columns
            if (isset($doctrineModel::$virtualColumns[$fieldName])) {
                continue;
            }
            $reflectionProperty = $reflectionClass->getProperty($fieldName);
            /** @var DatabaseColumn $databaseColumnAttributeInstance */
            $databaseColumnAttributeInstance = $reflectionProperty->getAttributeInstance(DatabaseColumn::class);

            $type = $metadata->getTypeOfField($fieldName);
            $column = $metadata->getColumnName($fieldName);
            $column = '`' . $column . '`';
            $columns[] = $column;
            $isIdentifier = false;
            if ($metadata->isIdentifier($fieldName)) {
                $isIdentifier = true;
            }
            if ($fieldName == $createdColumn) {
                $update[] = "$column = COALESCE(VALUES($column), $column)";
            } elseif ($isIdentifier) {
                // we need to set this explicitely, otherwise in case of updates we cannot access the id with lastInsertId();
                $update[] = "$column = LAST_INSERT_ID($column)";
            } elseif ($databaseColumnAttributeInstance?->isMergableJSONColumn) {
                $update[] = "$column = JSON_MERGE_PATCH(COALESCE($column,'{}'), COALESCE(VALUES($column),'{}'))";
            }
            elseif (isset($databaseColumnAttributeInstance->onUpdateAction)) {
                $update[] = "$column = " . $databaseColumnAttributeInstance->onUpdateAction;
            } else {
                $update[] = "$column = VALUES($column)";
            }
        }

        // Loop over models to get values, types, set clause, and update clause
        foreach ($doctrineModels as $doctrineModel) {
            $setRow = [];
            $typesRow = [];
            foreach ($columns as $index => $column) {
                $fieldName = $metadata->getFieldName(str_replace('`', '', $column));
                $type = $metadata->getTypeOfField($fieldName);
                $value = $metadata->getFieldValue($doctrineModel, $fieldName);
                // Handle Spatial Types
                if (isset(DatabaseColumn::SPATIAL_SQL_TYPES[$type])) {
                    $setRow[] = 'ST_GeomFromText(?)';
                    if ($value) {
                        $value = (string)$value;
                        $typesRow[] = 'string';
                    }
                } else {
                    $setRow[] = '?';
                    $typesRow[] = $type;
                }
                $values[] = $value;
            }
            $set[] = '(' . implode(', ', $setRow) . ')';
            $types = array_merge($types, $typesRow);
        }
        $ignoreStatement = $useInsertIgnore ? 'IGNORE' : '';
        $sql = "INSERT $ignoreStatement INTO " . reset($doctrineModels)->getTableName() . ' (' . implode(
                ', ',
                $columns
            ) . ')' . ' VALUES ' . implode(
                ', ',
                $set
            );
        if (!$useInsertIgnore) {
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $update);
        }
        $connection->executeStatement(
            $sql,
            $values,
            $types
        );
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
