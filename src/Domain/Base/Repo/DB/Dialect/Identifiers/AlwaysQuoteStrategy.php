<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Identifiers;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\Internal\SQLResultCasing;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\QuoteStrategy;

/**
 * D1: every table/column/join-column identifier the ORM emits on PostgreSQL is always quoted in its declared
 * case (`"Accounts"`, `"accountId"`), independent of the per-mapping `quoted` flag ({@see
 * \Doctrine\ORM\Mapping\DefaultQuoteStrategy}'s condition) that DDD entities never set. MariaDB tolerates an
 * unquoted mixed-case identifier; PostgreSQL silently folds it to lowercase — this is the exact shape of the
 * real-world failure that motivated this class: a login against a PostgreSQL 18 database whose tables were
 * created by {@see \DDD\Domain\Base\Repo\DB\Dialect\Schema\PostgresDdlRenderer} as `"Accounts"` failed with
 * `relation "accounts" does not exist` because the ORM emitted the unquoted `FROM Accounts a0_`.
 *
 * Registered only by {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}; MariaDB keeps
 * Doctrine's {@see \Doctrine\ORM\Mapping\DefaultQuoteStrategy} completely untouched, so the golden suite (T0)
 * stays byte-identical.
 *
 * Generated SELECT-list column aliases ({@see self::getColumnAlias()}) are deliberately left unquoted: Doctrine
 * builds them from alphanumerics and `_` only, then {@see SQLResultCasing} lower-cases them for a
 * `PostgreSQLPlatform` — an unquoted lowercase-only identifier already folds to the exact string the hydrator
 * looks the result column up by, so quoting it would change nothing.
 */
class AlwaysQuoteStrategy implements QuoteStrategy
{
    use SQLResultCasing;

    public function getColumnName($fieldName, ClassMetadata $class, AbstractPlatform $platform)
    {
        return $platform->quoteIdentifier($class->fieldMappings[$fieldName]['columnName']);
    }

    public function getTableName(ClassMetadata $class, AbstractPlatform $platform)
    {
        $tableName = $class->table['name'];

        if (!empty($class->table['schema'])) {
            // quoteIdentifier() itself splits on '.' and quotes each part, so a schema-qualified name does not
            // need its own emulate-schema branch here (PostgreSQL always supports real schemas).
            $tableName = $class->table['schema'] . '.' . $class->table['name'];
        }

        return $platform->quoteIdentifier($tableName);
    }

    public function getSequenceName(array $definition, ClassMetadata $class, AbstractPlatform $platform)
    {
        return $platform->quoteIdentifier($definition['sequenceName']);
    }

    public function getJoinColumnName(array $joinColumn, ClassMetadata $class, AbstractPlatform $platform)
    {
        return $platform->quoteIdentifier($joinColumn['name']);
    }

    public function getReferencedJoinColumnName(array $joinColumn, ClassMetadata $class, AbstractPlatform $platform)
    {
        return $platform->quoteIdentifier($joinColumn['referencedColumnName']);
    }

    public function getJoinTableName(array $association, ClassMetadata $class, AbstractPlatform $platform)
    {
        $tableName = $association['joinTable']['name'];

        if (isset($association['joinTable']['schema'])) {
            $tableName = $association['joinTable']['schema'] . '.' . $tableName;
        }

        return $platform->quoteIdentifier($tableName);
    }

    public function getIdentifierColumnNames(ClassMetadata $class, AbstractPlatform $platform)
    {
        $quotedColumnNames = [];

        foreach ($class->identifier as $fieldName) {
            if (isset($class->fieldMappings[$fieldName])) {
                $quotedColumnNames[] = $this->getColumnName($fieldName, $class, $platform);

                continue;
            }

            // Association defined as Id field.
            $joinColumns = $class->associationMappings[$fieldName]['joinColumns'];
            foreach ($joinColumns as $joinColumn) {
                $quotedColumnNames[] = $platform->quoteIdentifier($joinColumn['name']);
            }
        }

        return $quotedColumnNames;
    }

    public function getColumnAlias($columnName, $counter, AbstractPlatform $platform, ?ClassMetadata $class = null)
    {
        $columnName .= '_' . $counter;
        $columnName  = substr($columnName, -$platform->getMaxIdentifierLength());
        $columnName  = preg_replace('/[^A-Za-z0-9_]/', '', $columnName);
        $columnName  = is_numeric($columnName) ? '_' . $columnName : $columnName;

        return $this->getSQLResultCasing($platform, $columnName);
    }
}
