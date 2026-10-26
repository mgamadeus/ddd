<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerChild;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerGrandchild;
use DDD\Tests\Integration\Fixtures\Repo\DB\TieBreakerRows\DBTieBreakerParent;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * Every rights subquery an expand embeds renames its alias namespace apart from all others in the statement. The
 * rename used to be `_rights_<expand join alias>`; when an expand reused a root rights join named after its path, the
 * join `<base>_<prop>_rights` inside one subquery became `_rights_<join>_<prop>_rights` — exactly the rename of the
 * next subquery, whose expand reused the root's `<join>_<prop>_rights`. Doctrine: "already defined" (HTTP 500 on
 * Radbonus `supportTickets/list?expand=supportContact(expand=relatedAccounts)`).
 */
class RightsSubqueryAliasTest extends IntegrationTestCase
{
    public function testTwoRightsSubqueriesOverReusedPathShapedRightsJoinsGetDistinctAliases(): void
    {
        $parentAlias = 'TieBreakerParent';
        $childrenJoinAlias = $parentAlias . '_tieBreakerChildren_rights';
        $grandchildrenJoinAlias = $childrenJoinAlias . '_tieBreakerGrandchildren_rights';

        // the root's own rights joins, path-shaped, reused by the expands
        $mainQueryBuilder = DBTieBreakerParent::createQueryBuilder(true);
        $mainQueryBuilder->leftJoin("$parentAlias.tieBreakerChildren", $childrenJoinAlias)
            ->leftJoin("$childrenJoinAlias.tieBreakerGrandchildren", $grandchildrenJoinAlias)
            ->addSelect($childrenJoinAlias, $grandchildrenJoinAlias);

        // the child's rights join its grandchildren with the path-shaped alias `<base>_<prop>_rights`
        $childAlias = 'TieBreakerChild';
        $childRightsQueryBuilder = DBTieBreakerChild::createQueryBuilder(true);
        $childRightsQueryBuilder->leftJoin("$childAlias.tieBreakerGrandchildren", $childAlias . '_tieBreakerGrandchildren_rights')
            ->andWhere($childAlias . "_tieBreakerGrandchildren_rights.label = 'visible'");

        $grandchildAlias = 'TieBreakerGrandchild';
        $grandchildRightsQueryBuilder = DBTieBreakerGrandchild::createQueryBuilder(true);
        $grandchildRightsQueryBuilder->andWhere("$grandchildAlias.label = 'visible'");

        $expandOptions = new ExpandOptions();
        $expandOptions->applyConditionsFromReadRightsQueryBuilder($mainQueryBuilder, $childRightsQueryBuilder, $childAlias, $childrenJoinAlias);
        $expandOptions->applyConditionsFromReadRightsQueryBuilder($mainQueryBuilder, $grandchildRightsQueryBuilder, $grandchildAlias, $grandchildrenJoinAlias);

        $sql = $mainQueryBuilder->getQuery()->getSQL();

        $this->assertStringContainsString('IN (SELECT', $sql);
        $this->assertSame(2, substr_count($sql, 'IN (SELECT'));
    }
}
