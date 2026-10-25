<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Infrastructure\Reflection\ReflectionClass;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\QueryBuilder;

/**
 * §4.2 "`MariaDbUpsertBuilder` (wraps today's builders — MariaDB SQL byte-identical, golden suite proves it)":
 * `upsert()`, `updateColumnsById()` and `upsertMultiple()` delegate STRAIGHT BACK to the unmodified
 * `DoctrineEntityManager` methods below — the exact same code, same SQL, same golden files — so this class adds
 * the `UpsertBuilder` seam without touching one byte of MariaDB's rendering. `insertOrNull()` is new behaviour
 * (§4.3): no existing MariaDB method does a plain INSERT that swallows only a unique-key collision, so it is
 * built here directly, following the same "every initialized field, same column forms" pattern as `upsert()`'s
 * INSERT branch.
 */
final class MariaDbUpsertBuilder implements UpsertBuilder
{
    public function upsert(
        DoctrineEntityManager $entityManager,
        DoctrineModel &$doctrineModel,
        ?QueryBuilder $updateRightsQueryBuilder = null,
        ?array $onlyFieldNames = null
    ): ?int {
        return $entityManager->upsert($doctrineModel, $updateRightsQueryBuilder, $onlyFieldNames);
    }

    public function updateColumnsById(DoctrineEntityManager $entityManager, DoctrineModel &$doctrineModel, array $fieldNames): ?int
    {
        return $entityManager->updateColumnsById($doctrineModel, $fieldNames);
    }

    public function upsertMultiple(DoctrineEntityManager $entityManager, array &$doctrineModels, bool $useInsertIgnore = false): void
    {
        $entityManager->upsertMultiple($doctrineModels, $useInsertIgnore);
    }

    public function insertOrNull(DoctrineEntityManager $entityManager, DoctrineModel &$doctrineModel): ?int
    {
        $connection = $entityManager->getConnection();
        $metadata = $entityManager->getClassMetadata($doctrineModel::class);
        $reflectionClass = ReflectionClass::instance($doctrineModel::class);

        $columns = [];
        $placeholders = [];
        $values = [];
        $types = [];
        foreach ($metadata->getFieldNames() as $fieldName) {
            if (isset($doctrineModel::$virtualColumns[$fieldName])) {
                continue;
            }
            if ($metadata->isIdentifier($fieldName) && !$metadata->getFieldValue($doctrineModel, $fieldName)) {
                continue;
            }
            $reflectionProperty = $reflectionClass->getProperty($fieldName);
            if (!$reflectionProperty->isInitialized($doctrineModel)) {
                continue;
            }
            $value = $metadata->getFieldValue($doctrineModel, $fieldName);
            $type = $metadata->getTypeOfField($fieldName);
            $column = '`' . $metadata->getColumnName($fieldName) . '`';
            $columns[] = $column;
            if (isset(DatabaseColumn::SPATIAL_SQL_TYPES[$type])) {
                $placeholders[] = $value !== null ? 'ST_GeomFromText(?)' : '?';
                $values[] = $value !== null ? (string)$value : null;
                $types[] = 'string';
            } elseif ($type === 'vector') {
                $vectorDimensions = (int)($metadata->fieldMappings[$fieldName]['length'] ?? 0);
                if ($value !== null) {
                    $placeholders[] = 'VEC_FromText(?)';
                    $values[] = json_encode($value, JSON_THROW_ON_ERROR);
                    $types[] = 'string';
                } elseif ($vectorDimensions > 0) {
                    $placeholders[] = (string)DatabaseColumn::createMariaDbNullVectorDefault($vectorDimensions);
                } else {
                    $placeholders[] = '?';
                    $values[] = null;
                    $types[] = 'string';
                }
            } else {
                $placeholders[] = '?';
                $values[] = $value;
                $types[] = $type;
            }
        }

        $sql = 'INSERT INTO ' . $doctrineModel->getTableName() . ' (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ')';
        try {
            $connection->executeStatement($sql, $values, $types);
            return $doctrineModel->id ?? (int)$connection->lastInsertId();
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
