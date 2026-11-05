<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRowModel;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * App DQL compares boolean columns with the integer literals 0/1 (MariaDB BOOL = TINYINT(1)); PostgreSQL has no
 * boolean = integer operator. Real failure in a consuming app: `c0_."archived" <> 1` made every track upload fail on PG.
 * PostgresSqlWalker renders the literal as TRUE/FALSE; MariaDB SQL is unchanged.
 */
class BooleanLiteralDqlTest extends IntegrationTestCase
{
    /** @group query */
    public function testBooleanColumnComparedWithZeroAndOneLiteralsOnBothEngines(): void
    {
        static::insertRow('CounterRows', ['businessKey' => 'active', 'amount' => 1, 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => 'inactive', 'amount' => 2, 'isActive' => 0, 'occurredAt' => null]);
        $alias = DBCounterRowModel::MODEL_ALIAS;
        $keys = static function (string $where) use ($alias): array {
            $queryBuilder = DBCounterRow::createQueryBuilder(true);
            $queryBuilder->andWhere($where)->orderBy("$alias.businessKey", 'ASC');
            return array_map(static fn(DBCounterRowModel $m): string => $m->businessKey, $queryBuilder->getQuery()->getResult());
        };

        $this->assertSame(['active'], $keys("$alias.isActive = 1"));
        $this->assertSame(['inactive'], $keys("$alias.isActive = 0"));
        $this->assertSame(['inactive'], $keys("$alias.isActive != 1"));
        $this->assertSame(['active'], $keys("$alias.isActive <> 0 AND $alias.amount = 1"));
        $this->assertSame(['active'], $keys("1 = $alias.isActive"));
    }
}
