<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Repo;

use DDD\Domain\Base\Entities\QueryOptions\SelectOptions;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRows;
use DDD\Tests\Integration\Fixtures\Repo\DB\CounterRows\DBCounterRow;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * §6 step 4/5 item 5 ("remove the step-4a gating … for tests that only READ"): find-by-id and find-all go through
 * {@see \DDD\Domain\Base\Services\EntitiesService::find()} / {@see \DDD\Domain\Base\Services\EntitiesService::findAll()}
 * -> {@see \DDD\Domain\Base\Repo\DatabaseRepoEntity} / {@see \DDD\Domain\Base\Repo\DB\DBEntitySet}, which render
 * plain `SELECT … FROM "CounterRows" … WHERE "id" = ?` DQL with no custom DQL function and no write — exactly what
 * {@see \DDD\Domain\Base\Repo\DB\Dialect\Identifiers\AlwaysQuoteStrategy} (D1) and the PostgreSQL runtime
 * middlewares exist to make correct on both engines. Fixture rows are inserted via {@see
 * IntegrationTestCase::insertRow()} (generic SQL through the dialect), never Doctrine's own write path.
 */
class FindByIdAndFindAllTest extends IntegrationTestCase
{
    public function testFindByIdLoadsTheStoredRow(): void
    {
        static::insertRow('CounterRows', ['businessKey' => 'solo', 'amount' => 7, 'isActive' => 1]);
        $id = (int)static::connection()->fetchOne(
            'SELECT id FROM ' . static::quoteIdentifier('CounterRows') . ' WHERE ' . static::quoteIdentifier('businessKey') . ' = ?',
            ['solo']
        );

        $counterRow = CounterRow::getService()->find($id);

        $this->assertNotNull($counterRow);
        $this->assertSame($id, $counterRow->id);
        $this->assertSame('solo', $counterRow->businessKey);
        $this->assertSame(7, $counterRow->amount);
    }

    public function testFindByIdOnAMissingRowReturnsNull(): void
    {
        $this->assertNull(CounterRow::getService()->find(424242));
    }

    public function testFindAllLoadsEveryStoredRow(): void
    {
        static::insertRow('CounterRows', ['businessKey' => 'a', 'amount' => 1]);
        static::insertRow('CounterRows', ['businessKey' => 'b', 'amount' => 2]);
        static::insertRow('CounterRows', ['businessKey' => 'c', 'amount' => 3]);

        $counterRows = CounterRow::getService()->findAll();

        $this->assertNotNull($counterRows);
        $businessKeys = array_map(static fn (CounterRow $row): ?string => $row->businessKey, $counterRows->getElements());
        sort($businessKeys);
        $this->assertSame(['a', 'b', 'c'], $businessKeys);
    }

    public function testFindAllRespectsOffsetAndLimit(): void
    {
        static::insertRow('CounterRows', ['businessKey' => 'a', 'amount' => 1]);
        static::insertRow('CounterRows', ['businessKey' => 'b', 'amount' => 2]);
        static::insertRow('CounterRows', ['businessKey' => 'c', 'amount' => 3]);

        $counterRows = CounterRow::getService()->findAll(offset: 1, limit: 1);

        $this->assertNotNull($counterRows);
        $this->assertCount(1, $counterRows->getElements());
    }
    protected function insertSoloRowAndGetId(): int
    {
        static::insertRow('CounterRows', ['businessKey' => 'solo', 'label' => 'full', 'amount' => 7, 'isActive' => 1]);
        return (int)static::connection()->fetchOne(
            'SELECT id FROM ' . static::quoteIdentifier('CounterRows') . ' WHERE ' . static::quoteIdentifier('businessKey') . ' = ?',
            ['solo']
        );
    }

    protected function findAllWithRootSelect(string $select): void
    {
        CounterRows::setDefaultQueryOptionsSnapshot();
        try {
            $selectOptions = SelectOptions::fromString($select);
            CounterRows::getDefaultQueryOptions()->setSelect($selectOptions);
            CounterRow::getService()->findAll();
        } finally {
            CounterRows::restoreDefaultQueryOptionsSnapshot();
        }
    }

    /**
     * A list loaded with a root select maps partially loaded rows. They must not land in the entity registry under the
     * key of the plain find by id: a later find (here after the entity manager was cleared, as in a new request that
     * shares the extended registry cache) returned the partial entity, with every unselected property missing.
     */
    public function testAListWithARootSelectDoesNotServeItsPartialRowsToALaterFindById(): void
    {
        $id = $this->insertSoloRowAndGetId();

        $this->findAllWithRootSelect('id,businessKey');
        EntityManagerFactory::clearAllInstanceCaches();
        $counterRow = CounterRow::getService()->find($id);

        $this->assertSame(7, $counterRow->amount);
        $this->assertSame('full', $counterRow->label);
    }

    /** The same in one request: the entity manager still holds the partially loaded model from the list. */
    public function testAFindByIdAfterAListWithARootSelectLoadsTheWholeRowInTheSameRequest(): void
    {
        $id = $this->insertSoloRowAndGetId();

        $this->findAllWithRootSelect('id,businessKey');
        DoctrineEntityRegistry::clear();
        $counterRow = CounterRow::getService()->find($id);

        $this->assertSame(7, $counterRow->amount);
        $this->assertSame('full', $counterRow->label);
    }
    /** A list without a select still feeds the registry: its rows are complete and serve the later find by id. */
    public function testAListWithoutASelectStillRegistersItsRowsForTheFindById(): void
    {
        $id = $this->insertSoloRowAndGetId();
        DoctrineEntityRegistry::clear();

        CounterRow::getService()->findAll();
        $findQueryBuilderProbe = new class extends DBCounterRow {
            public function findQueryBuilderFor(int $id): DoctrineQueryBuilder
            {
                return $this->buildFindQueryBuilder($id);
            }
        };
        $registeredCounterRow = DoctrineEntityRegistry::getInstance()->get(DBCounterRow::class, $findQueryBuilderProbe->findQueryBuilderFor($id));

        $this->assertNotNull($registeredCounterRow);
        $this->assertSame(7, $registeredCounterRow->amount);
    }
}
