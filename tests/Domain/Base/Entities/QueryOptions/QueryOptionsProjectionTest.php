<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\AppliedQueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinition;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\SelectOptions;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\Projection\ProjectedLegacyEntities;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\Projection\ProjectedLegacyEntity;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\Projection\ProjectionHolderEntity;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\Projection\ProjectionTargetEntity;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A class without a table accepts QueryOptions in its own names, validated against the target class of its
 * QueryOptionsProjection and translated into the target's names for the loader.
 */
class QueryOptionsProjectionTest extends TestCase
{
    protected function setUp(): void
    {
        (new ReflectionProperty(LazyLoadRepo::class, 'defaultRepoType'))->setValue(null, 'DB');
    }

    public function testTheDefinitionsAreTheTargetsUnderTheOwnNames(): void
    {
        $filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(ProjectedLegacyEntity::class);

        $propertyNames = array_map(
            static fn(FiltersDefinition $filtersDefinition): string => (string)$filtersDefinition->propertyName,
            $filtersDefinitions->getElements()
        );
        sort($propertyNames);
        // title = the target's headline; the target's own title, onlyOnTarget and onlyOnLegacy are not exposed
        self::assertSame(['amount', 'dueOn', 'id', 'title'], array_values(array_intersect(
            $propertyNames,
            ['amount', 'dueOn', 'id', 'title', 'onlyOnTarget', 'onlyOnLegacy', 'headline']
        )));
        self::assertSame(ProjectionTargetEntity::class, $filtersDefinitions->referenceClassName);
        self::assertSame(
            FiltersDefinition::COLUMN_KIND_INT,
            $filtersDefinitions->getFilterDefinitionForPropertyName('amount')->columnKind
        );
        self::assertSame(
            FiltersDefinition::TEMPORAL_KIND_DAY,
            $filtersDefinitions->getFilterDefinitionForPropertyName('dueOn')->temporalKind
        );
    }

    public function testTheEntitySetTakesTheProjectionOfItsEntity(): void
    {
        $filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(ProjectedLegacyEntities::class);

        self::assertNotNull($filtersDefinitions->getFilterDefinitionForPropertyName('title'));
        self::assertNull($filtersDefinitions->getFilterDefinitionForPropertyName('headline'));
    }

    public function testFiltersOnRenamedAndSameNamedPropertiesAreValid(): void
    {
        $filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(ProjectedLegacyEntities::class);

        self::assertTrue(
            FiltersOptions::fromString("title eq 'Spring' and amount gt '3' and dueOn ge '2026-01-01'")
                ->validateAgainstDefinitions($filtersDefinitions)
        );
    }

    public function testAnUnknownOrUnexposedNameIsA400(): void
    {
        $filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(ProjectedLegacyEntities::class);

        foreach (["headline eq 'x'", "onlyOnTarget eq 'x'", "onlyOnLegacy eq 'x'"] as $filterQuery) {
            try {
                FiltersOptions::fromString($filterQuery)->validateAgainstDefinitions($filtersDefinitions);
                self::fail("$filterQuery must be rejected");
            } catch (BadRequestException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAValueThatDoesNotFitTheTargetKindIsA400(): void
    {
        $filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(ProjectedLegacyEntities::class);

        $this->expectException(BadRequestException::class);
        FiltersOptions::fromString("amount eq 'many'")->validateAgainstDefinitions($filtersDefinitions);
    }

    public function testExpandClausesOnALoadMethodPropertyAreValidatedThroughTheProjection(): void
    {
        $expandOptions = ExpandOptions::fromString(
            "legacyEntities(filters=title eq 'Spring';orderBy=amount desc;select=title,amount;top=5)"
        );

        self::assertTrue($expandOptions->validateAgainstDefinitionsFromReferenceClass(ProjectionHolderEntity::class));
    }

    public function testAnExpandClauseWithAnUnknownNameIsA400(): void
    {
        $expandOptions = ExpandOptions::fromString("legacyEntities(orderBy=headline desc)");

        $this->expectException(BadRequestException::class);
        $expandOptions->validateAgainstDefinitionsFromReferenceClass(ProjectionHolderEntity::class);
    }

    public function testTheProjectedOptionsUseTheTargetsNames(): void
    {
        $legacyQueryOptions = new AppliedQueryOptions(new QueryOptions(), ProjectedLegacyEntities::class);
        $filtersOptions = FiltersOptions::fromString("(title eq 'Spring' or title eq 'Summer') and amount gt '3'");
        $orderByOptions = OrderByOptions::fromString('title desc, amount');
        $selectOptions = SelectOptions::fromString('title, amount');
        $legacyQueryOptions->setTop(7)->setSkip(14);
        $legacyQueryOptions->setFilters($filtersOptions);
        $legacyQueryOptions->setOrderBy($orderByOptions);
        $legacyQueryOptions->setSelect($selectOptions);

        $projectedQueryOptions = $legacyQueryOptions->createProjectedQueryOptions();

        self::assertSame(ProjectionTargetEntity::class, $projectedQueryOptions->referenceClass);
        self::assertSame(7, $projectedQueryOptions->getTop());
        self::assertSame(14, $projectedQueryOptions->getSkip());
        self::assertSame(['headline', 'headline', 'amount'], $this->getFilteredPropertyNames($projectedQueryOptions->getFilters()));
        self::assertSame(['Spring', 'Summer', '3'], $this->getFilteredValues($projectedQueryOptions->getFilters()));
        self::assertSame(
            ['headline desc', 'amount asc'],
            array_map(
                static fn($orderByOption): string => $orderByOption->propertyName . ' ' . strtolower($orderByOption->direction),
                $projectedQueryOptions->getOrderBy()->getElements()
            )
        );
        self::assertSame(
            ['headline', 'amount'],
            array_values(array_map(static fn($selectOption): string => $selectOption->propertyName, $projectedQueryOptions->getSelect()->getElements()))
        );
        // the source options stay in the old names
        self::assertSame(['title', 'title', 'amount'], $this->getFilteredPropertyNames($legacyQueryOptions->getFilters()));
    }

    public function testOptionsWithoutAProjectionCannotBeProjected(): void
    {
        $this->expectException(BadRequestException::class);
        (new AppliedQueryOptions(new QueryOptions(), ProjectionTargetEntity::class))->createProjectedQueryOptions();
    }

    /**
     * @param FiltersOptions $filtersOptions
     * @return string[] the filtered property names, left to right
     */
    protected function getFilteredPropertyNames(FiltersOptions $filtersOptions): array
    {
        if ($filtersOptions->type === FiltersOptions::TYPE_EXPRESSION) {
            return [$filtersOptions->property];
        }
        $propertyNames = [];
        foreach ($filtersOptions->getElements() as $childFiltersOptions) {
            $propertyNames = array_merge($propertyNames, $this->getFilteredPropertyNames($childFiltersOptions));
        }
        return $propertyNames;
    }

    /**
     * @param FiltersOptions $filtersOptions
     * @return string[] the filter values as strings, left to right
     */
    protected function getFilteredValues(FiltersOptions $filtersOptions): array
    {
        if ($filtersOptions->type === FiltersOptions::TYPE_EXPRESSION) {
            return [(string)$filtersOptions->value];
        }
        $values = [];
        foreach ($filtersOptions->getElements() as $childFiltersOptions) {
            $values = array_merge($values, $this->getFilteredValues($childFiltersOptions));
        }
        return $values;
    }
}
