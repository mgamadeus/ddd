<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Crons\Cron;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The filter grammar asks for `'NULL'`, and the parser also accepts a bare `null`. Both must compare with
 * IS [NOT] NULL — a bound NULL parameter (`col = NULL`) matches no row in SQL, so a bare `null` used to filter
 * everything away without an error.
 */
class FiltersNullComparisonTest extends TestCase
{
    protected function setUp(): void
    {
        (new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType'))->setValue(null, 'DB');
    }

    protected function dqlWhereFor(string $filterQuery): string
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getExpressionBuilder')->willReturn(new Expr());
        $queryBuilder = new DoctrineQueryBuilder($entityManager);

        $filtersOptions = FiltersOptions::fromString($filterQuery);
        $this->assertTrue(
            $filtersOptions->validateAgainstDefinitions(FiltersDefinitions::getFiltersDefinitionsForReferenceClass(Cron::class)),
            $filterQuery
        );
        $filtersOptions->applyFiltersToDoctrineQueryBuilder($queryBuilder);

        return (string)$queryBuilder->getDQLPart('where');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function nullComparisons(): array
    {
        return [
            "eq 'NULL'" => ["description eq 'NULL'", 'IS NULL'],
            'eq null' => ['description eq null', 'IS NULL'],
            "ne 'NULL'" => ["description ne 'NULL'", 'IS NOT NULL'],
            'ne null' => ['description ne null', 'IS NOT NULL'],
        ];
    }

    /** @dataProvider nullComparisons */
    public function testANullComparisonBecomesIsNull(string $filterQuery, string $expectedComparison): void
    {
        $where = $this->dqlWhereFor($filterQuery);

        $this->assertStringContainsString($expectedComparison, $where);
        $this->assertStringNotContainsString('?', $where, 'NULL must not be bound as a parameter');
    }

    public function testAnOrdinaryValueIsStillBound(): void
    {
        $this->assertStringContainsString('= ?', $this->dqlWhereFor("description eq 'nightly'"));
    }
}
