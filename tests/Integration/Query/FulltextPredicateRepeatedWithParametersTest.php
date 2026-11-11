<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Query;

use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Tests\Integration\Fixtures\Entities\TranslatedRows\TranslatedRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows\DBTranslatedRow;
use DDD\Tests\Integration\Fixtures\Repo\DB\TranslatedRows\DBTranslatedRowModel;
use DDD\Tests\Integration\IntegrationTestCase;

/**
 * Fulltext filters with a prefix wildcard (`name fb 'chick*'`) whose PostgreSQL predicate appears more than once in
 * one statement, with further bound parameters. The predicate is SQL text with string literals; before PHP 8.4,
 * PDO's placeholder scanner reads a backslash inside a literal as an escape, so a literal `'\'` shifted its view of
 * where literals end and placeholders behind it were lost (SQLSTATE[HY093] "parameter was not defined"). A
 * distinct-root subquery (a filtered list with an expand) repeats the predicate the same way two filters do here.
 */
class FulltextPredicateRepeatedWithParametersTest extends IntegrationTestCase
{
    /**
     * @group query
     */
    public function testRepeatedPrefixFulltextPredicateWithFurtherParametersMatches(): void
    {
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'Grilled Chicken Burger']), 'title' => null]);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'Chickpea Salad']), 'title' => null]);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'Veggie Wrap']), 'title' => null]);

        $filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(TranslatedRow::class);
        $queryBuilder = DBTranslatedRow::createQueryBuilder(true);
        $filtersOptions = FiltersOptions::fromString("(name fb 'chick*' or name fb 'gw*') and id gt '0'");
        $filtersOptions->validateAgainstDefinitions($filtersDefinitions);
        $filtersOptions->applyFiltersToDoctrineQueryBuilder($queryBuilder);
        $query = $queryBuilder->getQuery();

        $this->assertStringNotContainsString(
            '\\',
            $query->getSQL(),
            'the SQL text carries no backslash that an older PDO placeholder scanner would read as an escape'
        );

        $matchedNames = array_map(
            static fn(DBTranslatedRowModel $model): string => $model->name['en'],
            $query->getResult()
        );
        sort($matchedNames);
        $this->assertSame(['Chickpea Salad', 'Grilled Chicken Burger'], $matchedNames);
    }

    /**
     * The LIKE escaping of a term still holds without a backslash in the SQL text: `%` and `_` in the search term
     * are literal characters, not wildcards.
     *
     * @group query
     */
    public function testWildcardCharactersInTheTermAreLiteral(): void
    {
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'Deal 100% Organic']), 'title' => null]);
        static::insertRow('TranslatedRows', ['name' => json_encode(['en' => 'Deal 1000 Items']), 'title' => null]);

        $filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(TranslatedRow::class);
        $queryBuilder = DBTranslatedRow::createQueryBuilder(true);
        $filtersOptions = FiltersOptions::fromString("name fb '100%' and id gt '0'");
        $filtersOptions->validateAgainstDefinitions($filtersDefinitions);
        $filtersOptions->applyFiltersToDoctrineQueryBuilder($queryBuilder);

        $matchedNames = array_map(
            static fn(DBTranslatedRowModel $model): string => $model->name['en'],
            $queryBuilder->getQuery()->getResult()
        );
        if (static::currentDriver() === 'pdo_pgsql') {
            $this->assertSame(['Deal 100% Organic'], $matchedNames, '% in the term is no wildcard on PostgreSQL');
        } else {
            $this->assertContains('Deal 100% Organic', $matchedNames);
        }
    }
}
