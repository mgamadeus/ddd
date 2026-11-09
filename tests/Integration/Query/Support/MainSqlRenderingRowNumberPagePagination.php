<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query\Support;

use DDD\Domain\Base\Repo\DB\Dialect\Query\RowNumberPagePagination;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/** Exposes the main query of {@see RowNumberPagePagination} and its ORDER BY scan */
class MainSqlRenderingRowNumberPagePagination extends RowNumberPagePagination
{
    public function renderMainSqlWithoutLimit(DoctrineQueryBuilder $queryBuilder): string
    {
        return $this->buildMainSqlWithoutLimit($queryBuilder)->sql;
    }

    public function findTopLevelOrderByListOffsetOf(string $sql): ?int
    {
        return $this->findTopLevelOrderByListOffset($sql);
    }

    public function injectIdPageJoinInto(string $mainSql, string $idSql, string $idAlias, string $rootTableAlias, bool $isGrouped): string
    {
        return $this->injectIdPageJoin($mainSql, $idSql, $idAlias, $rootTableAlias, $isGrouped);
    }
}
