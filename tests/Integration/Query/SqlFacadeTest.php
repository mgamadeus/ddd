<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Repo\DB\Dialect\UpdateFromJoinSpec;
use DDD\Domain\Base\Repo\DB\Dialect\Write\BindExpressionKind;
use DDD\Domain\Base\Repo\DB\Dialect\Write\ColumnWrite;
use DDD\Domain\Base\Repo\DB\Dialect\Write\ColumnWrites;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpdatePolicy;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertSpec;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRowModel;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * §4.3's app-facing SQL-fragment facade (dual-dialect architecture plan §6 build step 9 subset): every method
 * runs for real against both engines and the RESULT is compared/asserted — not just that each renders SOME SQL,
 * since the whole point of a facade is that the two renderings answer the same thing for the same input.
 */
class SqlFacadeTest extends IntegrationTestCase
{
    public function testNowIsARealRecentTimestamp(): void
    {
        $now = static::connection()->fetchOne('SELECT ' . static::dialect()->now());
        $this->assertNotEmpty($now);
        $this->assertLessThan(5, abs(time() - strtotime((string)$now)));
    }

    public function testIntervalAgoIsBeforeNow(): void
    {
        $dialect = static::dialect();
        $row = static::connection()->fetchAssociative(
            'SELECT ' . $dialect->now() . ' AS now_value, ' . $dialect->intervalAgo('10', 'MINUTE') . ' AS ago_value'
        );
        $diffSeconds = strtotime((string)$row['now_value']) - strtotime((string)$row['ago_value']);
        $this->assertGreaterThanOrEqual(598, $diffSeconds);
        $this->assertLessThanOrEqual(602, $diffSeconds);
    }

    public function testDateAddAddsTheIntervalOnBothEngines(): void
    {
        $dialect = static::dialect();
        $expr = $dialect->dateAdd("'2026-01-01 10:00:00'", '30', 'MINUTE');
        $value = static::connection()->fetchOne("SELECT $expr");
        $this->assertSame('2026-01-01 10:30:00', substr((string)$value, 0, 19));
    }

    public function testSecondsBetweenCountsWholeSeconds(): void
    {
        $dialect = static::dialect();
        $expr = $dialect->secondsBetween("'2026-01-01 10:00:00'", "'2026-01-01 10:05:30'");
        $this->assertSame(330, (int)static::connection()->fetchOne("SELECT $expr"));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function dateBuckets(): array
    {
        return [
            // 2026-03-04 is a Wednesday; its ISO/Monday-start week begins 2026-03-02
            'day' => ['day', '2026-03-04'],
            'week' => ['week', '2026-03-02'],
            'month' => ['month', '2026-03-01'],
        ];
    }

    /** @dataProvider dateBuckets */
    public function testDateBucketFloorsToTheExpectedStart(string $bucket, string $expected): void
    {
        $dialect = static::dialect();
        $expr = $dialect->dateBucket("'2026-03-04 15:30:00'", $bucket);
        $this->assertSame($expected, (string)static::connection()->fetchOne("SELECT $expr"));
    }

    public function testStringAggJoinsInOrderWithTheSeparator(): void
    {
        static::connection()->executeStatement('DELETE FROM ' . static::dialect()->quote('CounterRows'));
        static::insertRow('CounterRows', ['businessKey' => 'c', 'label' => 'gamma', 'amount' => 3, 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => 'a', 'label' => 'alpha', 'amount' => 1, 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => 'b', 'label' => 'beta', 'amount' => 2, 'isActive' => 1, 'occurredAt' => null]);

        $dialect = static::dialect();
        $label = $dialect->quote('label');
        $businessKey = $dialect->quote('businessKey');
        $table = $dialect->quote('CounterRows');
        $expr = $dialect->stringAgg($label, ' | ', $businessKey);
        $value = static::connection()->fetchOne("SELECT $expr FROM $table");
        $this->assertSame('alpha | beta | gamma', $value);
    }

    /** string_agg() takes text only on PostgreSQL: ints and booleans aggregate in MariaDB's implicit text form. */
    public function testStringAggOverIntAndBoolColumnsMatchesMariaDbsTextForm(): void
    {
        static::connection()->executeStatement('DELETE FROM ' . static::dialect()->quote('CounterRows'));
        static::insertRow('CounterRows', ['businessKey' => 'c', 'label' => 'gamma', 'amount' => 3, 'isActive' => 0, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => 'a', 'label' => 'alpha', 'amount' => 1, 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => 'b', 'label' => 'beta', 'amount' => 2, 'isActive' => 1, 'occurredAt' => null]);

        $dialect = static::dialect();
        $businessKey = $dialect->quote('businessKey');
        $table = $dialect->quote('CounterRows');
        $amounts = static::connection()->fetchOne('SELECT ' . $dialect->stringAgg($dialect->quote('amount'), ',', $businessKey) . " FROM $table");
        $flags = static::connection()->fetchOne('SELECT ' . $dialect->stringAgg($dialect->quote('isActive'), ',', $businessKey) . " FROM $table");

        $this->assertSame('1,2,3', $amounts);
        $this->assertSame('1,1,0', $flags);
    }

    /** The DQL functions render what the facade renders: STRING_AGG_ORDERED over an int, DATE_BUCKET by month. */
    public function testTheDqlStringAggAndDateBucketMatchTheFacade(): void
    {
        if (static::currentDriver() !== 'pdo_pgsql') {
            $this->markTestSkipped('STRING_AGG_ORDERED and DATE_BUCKET are registered on PostgreSQL only.');
        }
        static::connection()->executeStatement('DELETE FROM ' . static::dialect()->quote('CounterRows'));
        static::insertRow('CounterRows', ['businessKey' => 'b', 'label' => 'beta', 'amount' => 2, 'isActive' => 1, 'occurredAt' => '2026-03-04 15:30:00']);
        static::insertRow('CounterRows', ['businessKey' => 'a', 'label' => 'alpha', 'amount' => 1, 'isActive' => 1, 'occurredAt' => '2026-03-04 15:30:00']);

        $alias = DBCounterRowModel::MODEL_ALIAS;
        $queryBuilder = DBCounterRow::createQueryBuilder(true);
        $queryBuilder->select("STRING_AGG_ORDERED($alias.amount, ',', $alias.businessKey) AS amounts");
        $this->assertSame('1,2', $queryBuilder->getQuery()->getSingleScalarResult());

        $queryBuilder = DBCounterRow::createQueryBuilder(true);
        $queryBuilder->select("DATE_BUCKET($alias.occurredAt, 'month') AS bucket")->setMaxResults(1);
        $this->assertSame(
            (string)static::connection()->fetchOne('SELECT ' . static::dialect()->dateBucket("'2026-03-04 15:30:00'", 'month')),
            $queryBuilder->getQuery()->getSingleScalarResult()
        );
    }

    /**
     * An aggregate nested in an expression (`COUNT(DISTINCT x) + 1`, a ranking) still makes a single-row aggregate
     * query: the default order must not append the root id, which PostgreSQL rejects outside a GROUP BY (42803).
     */
    public function testAnAggregateInsideAnArithmeticExpressionGetsNoDefaultOrder(): void
    {
        static::connection()->executeStatement('DELETE FROM ' . static::dialect()->quote('CounterRows'));
        static::insertRow('CounterRows', ['businessKey' => 'a', 'label' => 'alpha', 'amount' => 3, 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => 'b', 'label' => 'beta', 'amount' => 3, 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => 'c', 'label' => 'gamma', 'amount' => 5, 'isActive' => 1, 'occurredAt' => null]);

        $alias = DBCounterRowModel::MODEL_ALIAS;
        $queryBuilder = DBCounterRow::createQueryBuilder(false);
        $queryBuilder->select("COUNT(DISTINCT $alias.amount) + 1 AS rankPosition")
            ->from(DBCounterRowModel::class, $alias)
            ->andWhere("$alias.amount >= :minimumAmount")
            ->setParameter('minimumAmount', 3);

        $this->assertSame(3, (int)$queryBuilder->getQuery()->getSingleScalarResult());
    }

    public function testCurrentDatabaseReturnsTheConnectedDatabaseName(): void
    {
        $value = static::connection()->fetchOne('SELECT ' . static::dialect()->currentDatabase());
        $this->assertNotEmpty($value);
    }

    public function testRowHash60IsA60CharDigestEqualForEqualInput(): void
    {
        $dialect = static::dialect();
        $expr = $dialect->rowHash60(["'alpha'", "'42'"]);
        $hash = (string)static::connection()->fetchOne("SELECT $expr");
        $this->assertSame(60, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{60}$/', $hash);
        // same input (even split across different column expressions) must render the same digest
        $otherExpr = $dialect->rowHash60(["'alpha'", "'4' || '2'"]);
        if ($dialect->name() === 'mariadb') {
            $otherExpr = $dialect->rowHash60(["'alpha'", "CONCAT('4','2')"]);
        }
        $otherHash = (string)static::connection()->fetchOne("SELECT $otherExpr");
        $this->assertSame($hash, $otherHash);
    }

    public function testJsonFieldTextExtractsAnUnquotedScalar(): void
    {
        $dialect = static::dialect();
        $jsonLiteral = $dialect->name() === 'mariadb' ? "'{\"color\": \"red\"}'" : "'{\"color\": \"red\"}'::json";
        $expr = $dialect->jsonFieldText($jsonLiteral, 'color');
        $this->assertSame('red', (string)static::connection()->fetchOne("SELECT $expr"));
    }

    public function testBytesEqualIsNullSafe(): void
    {
        $dialect = static::dialect();
        $nullSafeNullsEqual = 'SELECT CASE WHEN ' . $dialect->bytesEqual('NULL', 'NULL') . ' THEN 1 ELSE 0 END';
        $this->assertSame(1, (int)static::connection()->fetchOne($nullSafeNullsEqual));
        $nullVsValue = 'SELECT CASE WHEN ' . $dialect->bytesEqual('NULL', "'x'") . ' THEN 1 ELSE 0 END';
        $this->assertSame(0, (int)static::connection()->fetchOne($nullVsValue));
        $equalValues = 'SELECT CASE WHEN ' . $dialect->bytesEqual("'x'", "'x'") . ' THEN 1 ELSE 0 END';
        $this->assertSame(1, (int)static::connection()->fetchOne($equalValues));
    }

    public function testBoolLiteralRendersATruthyAndFalsyValue(): void
    {
        $dialect = static::dialect();
        $this->assertSame(1, (int)static::connection()->fetchOne('SELECT CASE WHEN ' . $dialect->boolLiteral(true) . ' THEN 1 ELSE 0 END'));
        $this->assertSame(0, (int)static::connection()->fetchOne('SELECT CASE WHEN ' . $dialect->boolLiteral(false) . ' THEN 1 ELSE 0 END'));
    }

    public function testUpdateFromJoinUpdatesTheTargetFromTheJoinedRow(): void
    {
        static::connection()->executeStatement('DELETE FROM ' . static::dialect()->quote('CounterRows'));
        static::insertRow('CounterRows', ['businessKey' => 'target', 'amount' => 1, 'label' => 'old', 'isActive' => 1, 'occurredAt' => null]);
        static::insertRow('CounterRows', ['businessKey' => 'source', 'amount' => 99, 'label' => 'new-label', 'isActive' => 1, 'occurredAt' => null]);

        $dialect = static::dialect();
        $spec = new UpdateFromJoinSpec(
            targetTable: 'CounterRows',
            targetAlias: 't',
            joinTable: 'CounterRows',
            joinAlias: 'j',
            joinOnSql: "j.{$dialect->quote('businessKey')} = 'source' AND t.{$dialect->quote('businessKey')} = 'target'",
            setAssignments: ['label' => "j.{$dialect->quote('label')}", 'amount' => "j.{$dialect->quote('amount')}"],
        );
        static::connection()->executeStatement($dialect->updateFromJoin($spec));

        $row = static::connection()->fetchAssociative(
            'SELECT ' . $dialect->quote('label') . ', ' . $dialect->quote('amount')
                . ' FROM ' . $dialect->quote('CounterRows') . " WHERE {$dialect->quote('businessKey')} = 'target'"
        );
        $this->assertSame('new-label', $row['label']);
        $this->assertSame(99, (int)$row['amount']);
    }

    public function testUpsertInsertsThenUpdatesOnConflict(): void
    {
        static::connection()->executeStatement('DELETE FROM ' . static::dialect()->quote('CounterRows'));
        $dialect = static::dialect();

        $spec = new UpsertSpec(
            table: 'CounterRows',
            columns: ColumnWrites::fromColumnWrites(
                new ColumnWrite('businessKey', 'facade-row', 'string'),
                new ColumnWrite('amount', 1, 'integer'),
                new ColumnWrite('isActive', true, 'boolean'),
                new ColumnWrite('displayOrder', 0, 'integer'),
            ),
            conflictTargetColumns: ['businessKey'],
        );
        $firstResult = $dialect->upsert(static::connection(), $spec);
        $this->assertTrue($firstResult->inserted);
        $this->assertNotNull($firstResult->id);

        $updateSpec = new UpsertSpec(
            table: 'CounterRows',
            columns: ColumnWrites::fromColumnWrites(
                new ColumnWrite('businessKey', 'facade-row', 'string'),
                new ColumnWrite('amount', 7, 'integer'),
                new ColumnWrite('isActive', true, 'boolean'),
                new ColumnWrite('displayOrder', 0, 'integer'),
            ),
            conflictTargetColumns: ['businessKey'],
        );
        $secondResult = $dialect->upsert(static::connection(), $updateSpec);
        $this->assertFalse($secondResult->inserted);
        $this->assertSame($firstResult->id, $secondResult->id);

        $storedAmount = (int)static::connection()->fetchOne(
            'SELECT ' . $dialect->quote('amount') . ' FROM ' . $dialect->quote('CounterRows')
                . " WHERE {$dialect->quote('businessKey')} = 'facade-row'"
        );
        $this->assertSame(7, $storedAmount);
    }

    public function testInsertOrNullLosesTheRaceWithoutMergingOntoTheWinner(): void
    {
        static::connection()->executeStatement('DELETE FROM ' . static::dialect()->quote('CounterRows'));
        $dialect = static::dialect();
        $winnerSpec = new UpsertSpec(
            table: 'CounterRows',
            columns: ColumnWrites::fromColumnWrites(
                new ColumnWrite('businessKey', 'race', 'string'),
                new ColumnWrite('amount', 1, 'integer'),
                new ColumnWrite('isActive', true, 'boolean'),
                new ColumnWrite('displayOrder', 0, 'integer'),
            ),
        );
        $winnerId = $dialect->insertOrNull(static::connection(), $winnerSpec);
        $this->assertIsInt($winnerId);

        $loserSpec = new UpsertSpec(
            table: 'CounterRows',
            columns: ColumnWrites::fromColumnWrites(
                new ColumnWrite('businessKey', 'race', 'string'),
                new ColumnWrite('amount', 999, 'integer'),
                new ColumnWrite('isActive', true, 'boolean'),
                new ColumnWrite('displayOrder', 0, 'integer'),
            ),
        );
        $this->assertNull($dialect->insertOrNull(static::connection(), $loserSpec));

        $storedAmount = (int)static::connection()->fetchOne(
            'SELECT ' . $dialect->quote('amount') . ' FROM ' . $dialect->quote('CounterRows')
                . " WHERE {$dialect->quote('businessKey')} = 'race'"
        );
        $this->assertSame(1, $storedAmount, 'the loser must never merge onto the race winner');
    }

    public function testUpsertAppliesTheRawActionUpdatePolicyOnConflict(): void
    {
        static::connection()->executeStatement('DELETE FROM ' . static::dialect()->quote('CounterRows'));
        $dialect = static::dialect();

        $spec = new UpsertSpec(
            table: 'CounterRows',
            columns: ColumnWrites::fromColumnWrites(
                new ColumnWrite('businessKey', 'counted', 'string'),
                new ColumnWrite(
                    'counter',
                    5,
                    'integer',
                    updatePolicy: UpdatePolicy::RAW_ACTION,
                    rawOnUpdateAction: '`counter` + COALESCE(VALUES(`counter`), 1)'
                ),
                new ColumnWrite('isActive', true, 'boolean'),
                new ColumnWrite('displayOrder', 0, 'integer'),
            ),
            conflictTargetColumns: ['businessKey'],
        );
        $dialect->upsert(static::connection(), $spec);
        $dialect->upsert(static::connection(), $spec);

        $storedCounter = (int)static::connection()->fetchOne(
            'SELECT ' . $dialect->quote('counter') . ' FROM ' . $dialect->quote('CounterRows')
                . " WHERE {$dialect->quote('businessKey')} = 'counted'"
        );
        $this->assertSame(10, $storedCounter);
    }
}
