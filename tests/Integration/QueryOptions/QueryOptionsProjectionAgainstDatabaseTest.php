<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\QueryOptions;

use DDD\Domain\Base\Entities\QueryOptions\AppliedQueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRow;
use DDD\Tests\Integration\Fixtures\Entities\CounterRows\CounterRows;
use DDD\Tests\Integration\Fixtures\Entities\LegacyCounterRows\LegacyCounterRows;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * Options received in the names of a class without a table, translated by its QueryOptionsProjection and applied to
 * the target class' repository, answer like the same options written in the target's names.
 */
class QueryOptionsProjectionAgainstDatabaseTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        static::insertRow('CounterRows', ['businessKey' => 'a', 'amount' => 4, 'isActive' => 1]);
        static::insertRow('CounterRows', ['businessKey' => 'b', 'amount' => 14, 'isActive' => 0]);
        static::insertRow('CounterRows', ['businessKey' => 'c', 'amount' => 20, 'isActive' => 1]);
        static::insertRow('CounterRows', ['businessKey' => 'd', 'amount' => 9, 'isActive' => 1]);
    }

    public function testLegacyFiltersOrderAndPagingAnswerOnTheTargetTable(): void
    {
        $legacyQueryOptions = new AppliedQueryOptions(new QueryOptions(), LegacyCounterRows::class);
        $filtersOptions = FiltersOptions::fromString("quantity gt '5' and isActive eq 'true'");
        $orderByOptions = OrderByOptions::fromString('quantity desc');
        $legacyQueryOptions->setFilters($filtersOptions);
        $legacyQueryOptions->setOrderBy($orderByOptions);
        $legacyQueryOptions->setTop(1)->setSkip(1);

        self::assertSame(['d'], $this->findCounterRowKeys($legacyQueryOptions->createProjectedQueryOptions(CounterRows::class)));
    }

    public function testARenamedKeyFiltersInAList(): void
    {
        $legacyQueryOptions = new AppliedQueryOptions(new QueryOptions(), LegacyCounterRows::class);
        $filtersOptions = FiltersOptions::fromString("key in ['a','c'] or quantity eq '14'");
        $orderByOptions = OrderByOptions::fromString('key');
        $legacyQueryOptions->setFilters($filtersOptions);
        $legacyQueryOptions->setOrderBy($orderByOptions);

        self::assertSame(['a', 'b', 'c'], $this->findCounterRowKeys($legacyQueryOptions->createProjectedQueryOptions(CounterRows::class)));
    }

    /**
     * The loader pattern: the translated options become the target set's defaults for one find.
     * @return string[] businessKey of the rows found, in result order
     */
    protected function findCounterRowKeys(AppliedQueryOptions $projectedQueryOptions): array
    {
        CounterRows::setDefaultQueryOptionsSnapshot();
        try {
            CounterRows::setDefaultQueryOptions($projectedQueryOptions);
            $counterRows = CounterRow::getService()->findAll();
        } finally {
            CounterRows::restoreDefaultQueryOptionsSnapshot();
        }
        return array_values(array_map(
            static fn(CounterRow $counterRow): string => (string)$counterRow->businessKey,
            $counterRows?->getElements() ?? []
        ));
    }
}
