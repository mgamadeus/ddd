<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlStatement;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerChild;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerGrandchild;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParent;
use DDD\Tests\Integration\Fixtures\Entities\TieBreakerRows\TieBreakerParents;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * D13 to-many child tie-breaker (dual-dialect architecture plan §3, "InnoDB returns rows in primary-key order with
 * no ORDER BY; a PostgreSQL heap has no such guarantee"): real Dineful failure this closes — an `Order` expanded
 * with its `orderItems` and their `orderItemOptionSelections` came back with the children in a different order on
 * PostgreSQL than on MariaDB, because {@see \DDD\Domain\Base\Repo\DB\Dialect\Query\DefaultOrderTreeWalker} only
 * ever appended the ROOT id tie-breaker. {@see TieBreakerParent} → {@see TieBreakerChild} (to-many) →
 * {@see TieBreakerGrandchild} (to-many) reproduce that exact shape with no golden-affecting entity change.
 *
 * NOT part of {@see IntegrationTestCase::FIXTURE_ENTITY_CLASSES} — same reasoning as
 * `ConflictKeyAndIdentityCatchUpTest`'s own fixtures (that constant is read with `self::`, so a subclass can't
 * extend it, and adding to it directly would grow every OTHER integration test class's fixture DDL capture): this
 * class manages its own three extra tables. Parent → child → grandchild order matters for CREATE on both engines
 * (MariaDB's `ALTER TABLE … ADD FOREIGN KEY` needs the referenced table to already exist; PostgreSQL's FK is added
 * in its own DDL phase with the same requirement) — create forward (parent, child, grandchild), drop reverse
 * (grandchild, child, parent): a FK-referenced table cannot be dropped first on either engine without `CASCADE`.
 *
 * Fixture rows are inserted through {@see IntegrationTestCase::insertRow()} with EXPLICIT, deliberately
 * out-of-numeric-order ids (never through the ORM), so a freshly populated PostgreSQL heap's physical
 * (insertion-order) scan would return the WRONG order if the tie-breaker were missing or broken — the fixture the
 * class docblock-level D13 gate asks for ("insert ids out of order"). MariaDB needs no such trick (InnoDB always
 * returns PK-clustered order), so both engines' assertions are the SAME expected id-ascending order.
 */
class ToManyChildTieBreakerTest extends IntegrationTestCase
{
    protected const array EXTRA_FIXTURE_ENTITY_CLASSES = [
        TieBreakerParent::class,
        TieBreakerChild::class,
        TieBreakerGrandchild::class,
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        // Reverse-order drop first, in case a previous run left the tables behind: a FK-referenced table cannot
        // be dropped (without CASCADE) while another table's FK still points at it.
        foreach (array_reverse(self::EXTRA_FIXTURE_ENTITY_CLASSES) as $entityClass) {
            static::dropExtraFixtureTable($entityClass);
        }
        foreach (self::EXTRA_FIXTURE_ENTITY_CLASSES as $entityClass) {
            static::createExtraFixtureTable($entityClass);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (static::isIntegrationDatabaseEnabled()) {
            foreach (array_reverse(self::EXTRA_FIXTURE_ENTITY_CLASSES) as $entityClass) {
                static::dropExtraFixtureTable($entityClass);
            }
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!static::isIntegrationDatabaseEnabled()) {
            return;
        }
        // Reverse order: deleting a parent row while a child row's FK still references it is the same ordering
        // problem the class docblock describes for DROP, just at the row level instead of the table level.
        foreach (array_reverse(self::EXTRA_FIXTURE_ENTITY_CLASSES) as $entityClass) {
            $connection = static::connection();
            $connection->executeStatement(
                'DELETE FROM ' . static::quoteIdentifier(DatabaseModel::fromEntityClass($entityClass)->sqlTableName)
            );
        }
    }

    /** Mirrors {@see \DDD\Tests\Integration\Repo\ConflictKeyAndIdentityCatchUpTest::createExtraFixtureTable()}. */
    protected static function createExtraFixtureTable(string $entityClass): void
    {
        $connection = static::connection();
        $databaseModel = DatabaseModel::fromEntityClass($entityClass);
        if (static::currentDriver() === 'pdo_pgsql') {
            $dialect = static::dialect();
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $dialect->quote($databaseModel->sqlTableName) . ' CASCADE');
            $statements = $dialect->ddlRenderer()->renderCreate($databaseModel);
            foreach ($statements->sortedByPhase() as $statement) {
                $connection->executeStatement($statement->sql);
            }
            return;
        }
        $connection->executeStatement('DROP TABLE IF EXISTS `' . $databaseModel->sqlTableName . '`');
        foreach (static::splitSqlStatements($databaseModel->getSql()) as $statement) {
            $connection->executeStatement($statement);
        }
    }

    protected static function dropExtraFixtureTable(string $entityClass): void
    {
        $connection = static::connection();
        $databaseModel = DatabaseModel::fromEntityClass($entityClass);
        $connection->executeStatement(
            'DROP TABLE IF EXISTS ' . static::quoteIdentifier($databaseModel->sqlTableName)
            . (static::currentDriver() === 'pdo_pgsql' ? ' CASCADE' : '')
        );
    }

    protected function findParentIdByBusinessKey(string $businessKey): int
    {
        return (int)static::connection()->fetchOne(
            'SELECT ' . static::quoteIdentifier('id') . ' FROM ' . static::quoteIdentifier('TieBreakerParents')
            . ' WHERE ' . static::quoteIdentifier('businessKey') . ' = ?',
            [$businessKey]
        );
    }

    // ───────────────────────────────────────────────────────── expand (no pagination)

    /**
     * The real failure's exact shape: one root, expanded two levels deep (`tieBreakerChildren(expand=
     * tieBreakerGrandchildren)`) through {@see \DDD\Domain\Base\Services\EntitiesService::find()}'s eager join
     * path — the same path `Order::getService()->find($id)` takes with `expand=orderItems(expand=
     * orderItemOptionSelections)`. Children and grandchildren are inserted with explicit ids in reverse numeric
     * order, so a PostgreSQL heap scan with no tie-breaker would return them backwards; asserting plain ascending
     * id order on BOTH drivers is this test's gate.
     */
    public function testExpandedToManyChildrenAndGrandchildrenComeBackInIdAscendingOrderOnBothEngines(): void
    {
        static::insertRow('TieBreakerParents', ['businessKey' => 'expand-root']);
        $parentId = $this->findParentIdByBusinessKey('expand-root');

        // Three children, explicit ids inserted in REVERSE numeric order (third, first, second).
        $childFirstId = $parentId * 1000 + 1;
        $childSecondId = $parentId * 1000 + 2;
        $childThirdId = $parentId * 1000 + 3;
        static::insertRow('TieBreakerChildren', ['id' => $childThirdId, 'tieBreakerParentId' => $parentId, 'label' => 'third']);
        static::insertRow('TieBreakerChildren', ['id' => $childFirstId, 'tieBreakerParentId' => $parentId, 'label' => 'first']);
        static::insertRow('TieBreakerChildren', ['id' => $childSecondId, 'tieBreakerParentId' => $parentId, 'label' => 'second']);

        // Grandchildren of the FIRST child only (two is enough to prove the grandchild level; the other two
        // children stay leaf-empty, which must not disturb their own ordering among the three children).
        $grandchildFirstId = $childFirstId * 10 + 1;
        $grandchildSecondId = $childFirstId * 10 + 2;
        static::insertRow(
            'TieBreakerGrandchildren',
            ['id' => $grandchildSecondId, 'tieBreakerChildId' => $childFirstId, 'label' => 'second']
        );
        static::insertRow(
            'TieBreakerGrandchildren',
            ['id' => $grandchildFirstId, 'tieBreakerChildId' => $childFirstId, 'label' => 'first']
        );

        TieBreakerParent::setDefaultQueryOptionsSnapshot();
        try {
            $expandOptions = ExpandOptions::fromString('tieBreakerChildren(expand=tieBreakerGrandchildren)');
            TieBreakerParent::getDefaultQueryOptions()->setExpand($expandOptions);
            $parent = TieBreakerParent::getService()->find($parentId);
        } finally {
            TieBreakerParent::restoreDefaultQueryOptionsSnapshot();
        }

        $this->assertNotNull($parent, 'the eager expand join path must still find the root');
        $childIds = array_map(
            static fn(TieBreakerChild $child): int => $child->id,
            $parent->tieBreakerChildren->getElements()
        );
        $this->assertSame(
            [$childFirstId, $childSecondId, $childThirdId],
            $childIds,
            'to-many children must come back id-ascending regardless of physical insertion order'
        );

        $grandchildIds = array_map(
            static fn(TieBreakerGrandchild $grandchild): int => $grandchild->id,
            $parent->tieBreakerChildren->first()->tieBreakerGrandchildren->getElements()
        );
        $this->assertSame(
            [$grandchildFirstId, $grandchildSecondId],
            $grandchildIds,
            'to-many grandchildren (second join level) must also come back id-ascending'
        );
    }

    /**
     * D13's stated exclusion verified directly: `expand=` on a root entity never produces `GROUP BY`/`HAVING`/
     * `DISTINCT`/an aggregate select, so {@see \DDD\Domain\Base\Repo\DB\Dialect\Query\DefaultOrderTreeWalker}'s
     * early-return guards for those shapes are never the reason this query's tie-breakers would be missing — this
     * test's green result on PostgreSQL is itself the proof that the guard didn't swallow the to-many append.
     */
    public function testExpandWithAnExplicitRootOrderByStillGetsTheChildTieBreaker(): void
    {
        static::insertRow('TieBreakerParents', ['businessKey' => 'expand-root-explicit-order']);
        $parentId = $this->findParentIdByBusinessKey('expand-root-explicit-order');

        $childHighId = $parentId * 1000 + 2;
        $childLowId = $parentId * 1000 + 1;
        static::insertRow('TieBreakerChildren', ['id' => $childHighId, 'tieBreakerParentId' => $parentId, 'label' => 'high']);
        static::insertRow('TieBreakerChildren', ['id' => $childLowId, 'tieBreakerParentId' => $parentId, 'label' => 'low']);

        TieBreakerParent::setDefaultQueryOptionsSnapshot();
        try {
            $expandOptions = ExpandOptions::fromString('tieBreakerChildren');
            $appliedOptions = TieBreakerParent::getDefaultQueryOptions();
            $appliedOptions->setExpand($expandOptions);
            // An explicit root orderBy (here, trivially, on the already-unique businessKey) already satisfies
            // DefaultOrderTreeWalker::endsOnRootIdentifier()'s inverse — i.e. it does NOT end on the root id — so
            // the walker still appends the root id tie-breaker AND the to-many child tie-breaker after it.
            $orderByOptions = \DDD\Domain\Base\Entities\QueryOptions\OrderByOptions::fromString('businessKey asc');
            $appliedOptions->setOrderBy($orderByOptions);
            $parent = TieBreakerParent::getService()->find($parentId);
        } finally {
            TieBreakerParent::restoreDefaultQueryOptionsSnapshot();
        }

        $this->assertNotNull($parent);
        $childIds = array_map(
            static fn(TieBreakerChild $child): int => $child->id,
            $parent->tieBreakerChildren->getElements()
        );
        $this->assertSame([$childLowId, $childHighId], $childIds);
    }

    // ───────────────────────────────────────────────────────── pagination with joins (RowNumberPagePagination)

    /**
     * D2/D13 interaction this task's gate explicitly calls out: the join+limit branch
     * ({@see \DDD\Domain\Base\Repo\DB\Dialect\Query\RowNumberPagePagination} on PostgreSQL) must still carry the
     * to-many child tie-breaker on its MAIN query, while its id-page query orders roots only. Three roots, two
     * children each (ids inserted high-then-low so a broken main-query tie-breaker would show up immediately);
     * `findAll(limit: 2)` exercises `DoctrineQueryBuilder::applyDistinctSubqueryIfNeededAndGetResult()`'s
     * `hasJoins && hasLimitOrOffset` branch.
     */
    public function testPaginationWithJoinsStillOrdersEachPagesChildrenByIdAscendingOnBothEngines(): void
    {
        $parentIds = [];
        foreach (['page-a', 'page-b', 'page-c'] as $businessKey) {
            static::insertRow('TieBreakerParents', ['businessKey' => $businessKey]);
            $parentId = $this->findParentIdByBusinessKey($businessKey);
            $parentIds[$businessKey] = $parentId;

            $lowId = $parentId * 1000 + 1;
            $highId = $parentId * 1000 + 2;
            // High id inserted FIRST (reverse of ascending id order) for every parent, on purpose.
            static::insertRow('TieBreakerChildren', ['id' => $highId, 'tieBreakerParentId' => $parentId, 'label' => 'high']);
            static::insertRow('TieBreakerChildren', ['id' => $lowId, 'tieBreakerParentId' => $parentId, 'label' => 'low']);
        }

        TieBreakerParents::setDefaultQueryOptionsSnapshot();
        try {
            $expandOptions = ExpandOptions::fromString('tieBreakerChildren');
            TieBreakerParents::getDefaultQueryOptions()->setExpand($expandOptions);
            $page = TieBreakerParent::getService()->findAll(offset: 0, limit: 2);
        } finally {
            TieBreakerParents::restoreDefaultQueryOptionsSnapshot();
        }

        $this->assertNotNull($page);
        $this->assertCount(2, $page->getElements(), 'the id page must still return exactly one page of DISTINCT roots');

        $expectedRootIds = [$parentIds['page-a'], $parentIds['page-b']];
        $actualRootIds = array_map(static fn(TieBreakerParent $parent): int => $parent->id, $page->getElements());
        $this->assertSame($expectedRootIds, $actualRootIds, 'the id-page query orders roots by id ascending, unaffected by this change');

        foreach ($page->getElements() as $parent) {
            $childIds = array_map(
                static fn(TieBreakerChild $child): int => $child->id,
                $parent->tieBreakerChildren->getElements()
            );
            $expectedChildIds = [$parent->id * 1000 + 1, $parent->id * 1000 + 2];
            $this->assertSame(
                $expectedChildIds,
                $childIds,
                "root {$parent->businessKey}'s children must be id-ascending on the paginated MAIN query too"
            );
        }
    }

    // ───────────────────────────────────────────────────────── lazy-loaded EntitySet (no join, root tie-breaker only)

    /**
     * D13 gate: "cover lazy-loaded EntitySet queries (a child set loaded by its own query: it is a root query, so
     * the root tie-breaker should already apply — verify)". A STANDALONE grandchildren load (triggered by
     * property access, never going through `expand=`) is its OWN root query (`FROM TieBreakerGrandchildren g
     * WHERE g.tieBreakerChildId = :id`, no join at all) — {@see \DDD\Domain\Base\Repo\DB\Dialect\Query\DefaultOrderTreeWalker}'s
     * pre-existing root-id tie-breaker (not this task's new to-many branch) is what orders it, and this test's
     * green result on PostgreSQL is the verification the gate asks for, not a new code path.
     */
    public function testLazyLoadedGrandchildrenSetComesBackIdAscendingThroughTheRootTieBreakerAlone(): void
    {
        static::insertRow('TieBreakerParents', ['businessKey' => 'lazy-load-root']);
        $parentId = $this->findParentIdByBusinessKey('lazy-load-root');

        $childId = $parentId * 1000 + 1;
        static::insertRow('TieBreakerChildren', ['id' => $childId, 'tieBreakerParentId' => $parentId, 'label' => 'only']);

        $grandchildHighId = $childId * 10 + 2;
        $grandchildLowId = $childId * 10 + 1;
        static::insertRow('TieBreakerGrandchildren', ['id' => $grandchildHighId, 'tieBreakerChildId' => $childId, 'label' => 'high']);
        static::insertRow('TieBreakerGrandchildren', ['id' => $grandchildLowId, 'tieBreakerChildId' => $childId, 'label' => 'low']);

        $child = TieBreakerChild::getService()->find($childId);
        $this->assertNotNull($child);

        // No expand= was ever set on TieBreakerChild's default QueryOptions — reading the property triggers
        // LazyLoadTrait's on-demand, standalone load of TieBreakerGrandchildren, a plain root query with no join.
        $grandchildIds = array_map(
            static fn(TieBreakerGrandchild $grandchild): int => $grandchild->id,
            $child->tieBreakerGrandchildren->getElements()
        );
        $this->assertSame([$grandchildLowId, $grandchildHighId], $grandchildIds);
    }
}
