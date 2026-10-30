<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRowModel;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * A DQL UPDATE / DELETE whose WHERE has a correlated subquery referencing the root alias: the root renders as the
 * quoted table name on PostgreSQL (before: bare `CounterRows."amount"`, folded to `counterrows` — "missing FROM-clause
 * entry for table"). MariaDB unchanged.
 */
class CorrelatedSubqueryWriteTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['low' => 1, 'mid' => 5, 'high' => 9] as $businessKey => $amount) {
            static::insertRow('CounterRows', ['businessKey' => $businessKey, 'amount' => $amount, 'isActive' => 1, 'occurredAt' => null]);
        }
    }

    /** @return array<string, int> businessKey → amount */
    protected function storedAmounts(): array
    {
        $dialect = static::dialect();
        $rows = static::connection()->fetchAllAssociative(
            'SELECT ' . $dialect->quote('businessKey') . ' AS k, ' . $dialect->quote('amount') . ' AS a FROM ' . $dialect->quote('CounterRows')
            . ' ORDER BY ' . $dialect->quote('businessKey')
        );
        return array_combine(array_column($rows, 'k'), array_map('intval', array_column($rows, 'a')));
    }

    public function testUpdateWithACorrelatedNotExists(): void
    {
        $model = DBCounterRowModel::class;
        // the row no other row exceeds: the maximum
        EntityManagerFactory::getInstance()->createQuery(
            "UPDATE $model counterRow SET counterRow.amount = 0
             WHERE NOT EXISTS (SELECT other.id FROM $model other WHERE other.amount > counterRow.amount)"
        )->execute();

        self::assertSame(['high' => 0, 'low' => 1, 'mid' => 5], $this->storedAmounts());
    }

    public function testDeleteWithACorrelatedExists(): void
    {
        $model = DBCounterRowModel::class;
        EntityManagerFactory::getInstance()->createQuery(
            "DELETE FROM $model counterRow
             WHERE EXISTS (SELECT other.id FROM $model other WHERE other.amount > counterRow.amount)"
        )->execute();

        self::assertSame(['high' => 9], $this->storedAmounts());
    }
}
