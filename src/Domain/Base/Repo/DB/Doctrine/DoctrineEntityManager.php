<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use Doctrine\Common\Cache\Psr6\InvalidArgument;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\QueryBuilder;
use ReflectionException;

class DoctrineEntityManager extends EntityManager
{
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
    public function upsert(DoctrineModel &$doctrineModel, ?QueryBuilder $updateRightsQueryBuilder = null): ?int
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

        foreach ($metadata->getFieldNames() as $fieldName) {
            $value = $metadata->getFieldValue($doctrineModel, $fieldName);

            $column = $metadata->getColumnName($fieldName);
            $column = '`' . $column . '`';
            if ($metadata->isIdentifier($fieldName)) {
                if ($value !== null && $value) {
                    $hasId = true;
                } else {
                    // we need to set this explicitely, otherwise in case of updates we cannot access the id with lastInsertId();
                    $update[] = "{$column} = LAST_INSERT_ID({$column})";
                    continue;
                }
            }
            $reflectionProperty = $metadata->getReflectionProperty($fieldName);
            if (!$reflectionProperty->isInitialized($doctrineModel)) {
                continue;
            }
            $columns[] = $column;
            $values[] = $value;
            $types[] = $metadata->getTypeOfField($fieldName);
            $set[] = '?';

            // Check if in column JSON contents should be merged with JSON_MERGE_PATCH
            if (isset($doctrineModel->jsonMergableColumns[$fieldName]) && is_array($value)) {
                $jsonValue = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $update[] = "{$column} = JSON_MERGE_PATCH({$column}, '$jsonValue')";
            } elseif ($fieldName == $createdColumn) {
                // we do not execute updates on created columns
                $update[] = "{$column} = COALESCE(VALUES($column), $column)";
            } else {
                $update[] = "{$column} = VALUES({$column})";
            }
        }

        $checkUpdateRights = false;
        if (!$connection->isTransactionActive() && $updateRightsQueryBuilder) {
            $checkUpdateRights = true;
        }
        $modelAlias = $doctrineModel::MODEL_ALIAS;

        // first we check if we perform an update or insert operation. If we already have an id, then we need to check before if
        // rights are sufficient to update the Entity.
        $checkedRightsOnUpdateOperation = false;
        if ($checkUpdateRights && isset($doctrineModel->id)) {
            $checkedRightsOnUpdateOperation = true;
            $checkRightsQueryBuilder = clone $updateRightsQueryBuilder;
            $checkRightsQueryBuilder->andWhere("{$modelAlias}.id = :entityId");
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
        // we need to start a transaction to be able to roll it back only if we did not perform an update operation
        if ($checkUpdateRights && !$checkedRightsOnUpdateOperation) {
            $connection->beginTransaction();
        }
        $connection->executeStatement(
            'INSERT INTO ' . $doctrineModel->getTableName() . ' (' . implode(', ', $columns) . ')' .
            ' VALUES (' . implode(', ', $set) . ')' .
            ' ON DUPLICATE KEY UPDATE ' . implode(', ', $update),
            $values,
            $types
        );

        $entityId = $doctrineModel->id ?? (int)$connection->lastInsertId();

        // if we need to check rights and we did not perform a check on an update operation, we are checking an insert operation here.
        // the check os done in a way, that
        if ($checkUpdateRights && !$checkedRightsOnUpdateOperation) {
            $modelAlias = $doctrineModel::MODEL_ALIAS;

            $checkRightsQueryBuilder = clone $updateRightsQueryBuilder;
            $checkRightsQueryBuilder->andWhere("{$modelAlias}.id = :entityId");
            $checkRightsQueryBuilder->setParameter('entityId', $entityId);

            $loadedOrmInstanceWithUpdateRightsQueryApplied = $checkRightsQueryBuilder->getQuery()->setMaxResults(
                1
            )->getResult();
            $loadedOrmInstanceWithUpdateRightsQueryApplied = $loadedOrmInstanceWithUpdateRightsQueryApplied[0] ?? null;

            if (!$loadedOrmInstanceWithUpdateRightsQueryApplied) {
                // no access to Entity, rolling back
                $connection->rollBack();
                $authAccount = AuthService::instance()->getAccount() ?? null;
                $authAccountId = $authAccount ? $authAccount->id : '(not logged in)';
                throw new ForbiddenException(
                    'Account ' . $authAccountId . ' has no permission to update or create Entity ' . $doctrineModel::ENTITY_CLASS
                );
            }
            $connection->commit();
        }
        return $entityId;
    }

    /**
     * Upsert multiple entities with JSON_MERGE_PATCH for specified JSON mergeable columns.
     *
     * @param DoctrineModel[] $doctrineModels
     * @return void
     * @throws Exception
     */
    public function upsertMultiple(array &$doctrineModels): void
    {
        if (!count($doctrineModels)) {
            return;
        }
        $connection = $this->getConnection();
        $metadata = $this->getClassMetadata(reset($doctrineModels)::class);
        $jsonMergableColumns = reset($doctrineModels)->jsonMergableColumns;

        foreach ($doctrineModels as $doctrineModel) {
            $columns = [];
            $values = [];
            $types = [];
            $update = [];
            foreach ($metadata->getFieldNames() as $fieldName) {
                $column = $metadata->getColumnName($fieldName);
                $value = $metadata->getFieldValue($doctrineModel, $fieldName);
                $type = $metadata->getTypeOfField($fieldName);

                if (array_key_exists($fieldName, $jsonMergableColumns) && $jsonMergableColumns[$fieldName]) {
                    // JSON-encode value for JSON mergeable columns
                    $encodedValue = json_encode($value);
                    if ($encodedValue === false) {
                        // Handle JSON encoding error
                        throw new Exception('JSON encoding error for field ' . $fieldName);
                    }
                    $update[] = "`$column` = JSON_MERGE_PATCH(`$column`, ?)";
                    $values[] = $encodedValue; // Use encoded value
                    $types[] = 'json'; // Specify type as 'json'
                } else {
                    // Regular update for non-JSON columns
                    $columns[] = "`$column`";
                    $values[] = $value;
                    $types[] = $type;
                    $update[] = "`$column` = VALUES(`$column`)";
                }
            }

            $sql = 'INSERT INTO ' . $doctrineModel->getTableName() . ' (' . implode(', ', $columns) . ')' .
                ' VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')' .
                ' ON DUPLICATE KEY UPDATE ' . implode(', ', $update);

            $connection->executeStatement($sql, $values, $types);
        }
    }


    public function createQuery($dql = ''): DoctrineQuery
    {
        $query = new DoctrineQuery($this);

        if (!empty($dql)) {
            $query->setDQL($dql);
        }

        return $query;
    }
}