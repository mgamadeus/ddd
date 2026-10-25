<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Query;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Utility\LockSqlHelper;
use RuntimeException;

/**
 * §4.8 (dual-dialect architecture plan): the default output walker for every non-paginated PostgreSQL query —
 * registered via `Query::HINT_CUSTOM_OUTPUT_WALKER` by
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect::configureOrm()}. Two responsibilities beyond the stock
 * {@see SqlWalker}:
 *
 * 1. **NULL ordering** ({@see NullsOrderingTrait}): `walkOrderByItem()` appends `NULLS FIRST`/`NULLS LAST` so a
 *    nullable `ORDER BY` matches MariaDB's NULL-as-smallest ordering.
 * 2. **Row locking without the paginator.** `walkSelectStatement()` never calls `parent::walkSelectStatement()` —
 *    that method finalises through {@see \Doctrine\ORM\Query\Exec\SingleSelectSqlFinalizer}, whose lock clause is
 *    a bare `FOR UPDATE`/`FOR SHARE` that PostgreSQL REJECTS the moment the query has an outer join ("FOR UPDATE
 *    cannot be applied to the nullable side of an outer join"). This walker builds the same
 *    `createSqlForFinalizer()` SQL, applies `modifyLimitQuery()` itself, then appends `FOR UPDATE OF <root alias>`
 *    when the query has any join (restricting the lock to the root table only) or a bare `FOR UPDATE` when it has
 *    none; `PESSIMISTIC_READ` renders `FOR SHARE` unconditionally (`FOR SHARE OF` has the same outer-join
 *    restriction, but no fixture here exercises a locked read with joins — a future one would need the same `OF`
 *    treatment). Row locking is never combined with {@see PostgresLimitSubqueryOutputWalker} — a locked, paginated,
 *    joined query is out of this step's scope (architecture plan §4.8: "Row locking is never combined with the
 *    pagination walker").
 */
class PostgresSqlWalker extends SqlWalker
{
    use NullsOrderingTrait;
    use LockSqlHelper;

    /** {@inheritDoc} */
    public function walkOrderByItem($orderByItem)
    {
        $sql = parent::walkOrderByItem($orderByItem);

        if ($orderByItem->expression instanceof AST\Subselect) {
            return $sql;
        }

        return $this->appendNullsOrdering($sql, strtoupper($orderByItem->type), $orderByItem->expression);
    }

    /** {@inheritDoc} */
    public function walkSelectStatement(AST\SelectStatement $AST)
    {
        $platform = $this->getConnection()->getDatabasePlatform();
        $query = $this->getQuery();

        $sql = $this->createSqlForFinalizer($AST);
        $sql = $platform->modifyLimitQuery($sql, $query->getMaxResults(), $query->getFirstResult());

        $lockMode = $query->getHint(Query::HINT_LOCK_MODE) ?: LockMode::NONE;
        if (
            $lockMode !== LockMode::NONE
            && $lockMode !== LockMode::OPTIMISTIC
            && $lockMode !== LockMode::PESSIMISTIC_READ
            && $lockMode !== LockMode::PESSIMISTIC_WRITE
        ) {
            throw QueryException::invalidLockMode();
        }

        if ($lockMode === LockMode::PESSIMISTIC_READ) {
            $sql .= ' ' . $this->getReadLockSQL($platform);
        } elseif ($lockMode === LockMode::PESSIMISTIC_WRITE) {
            $sql .= ' ' . $this->writeLockClause($AST, $platform);
        }

        return $sql;
    }

    /** `FOR UPDATE OF <root>` when the query has any join, plain `FOR UPDATE` otherwise. */
    protected function writeLockClause(AST\SelectStatement $AST, $platform): string
    {
        $fromDeclarations = $AST->fromClause->identificationVariableDeclarations;
        if (count($fromDeclarations) !== 1) {
            return $this->getWriteLockSQL($platform);
        }

        $rootDeclaration = $fromDeclarations[0];
        if (count($rootDeclaration->joins) === 0) {
            return $this->getWriteLockSQL($platform);
        }

        $rangeVariableDeclaration = $rootDeclaration->rangeVariableDeclaration;
        if (!$rangeVariableDeclaration instanceof AST\RangeVariableDeclaration) {
            return $this->getWriteLockSQL($platform);
        }

        $rootAlias = $rangeVariableDeclaration->aliasIdentificationVariable;
        $rootClass = $this->getMetadataForDqlAlias($rootAlias);
        $rootTableAlias = $this->getSQLTableAlias($rootClass->getTableName(), $rootAlias);

        return 'FOR UPDATE OF ' . $rootTableAlias;
    }
}
