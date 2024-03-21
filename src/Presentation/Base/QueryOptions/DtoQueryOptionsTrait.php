<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\QueryOptions;

use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use ReflectionException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides query options logic to DTO
 */
trait DtoQueryOptionsTrait
{
    /** @var int Number of results to be skipped / offsetted */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public int $skip = 0;

    /** @var int The number of results to be returned */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $top = null;

    /**
     * @var string Definition of orderBy follwing the syntax:
     * <details><summary>Definitions and examples:</summary>
     *
     * `<property> <direction>?, <property> <direction>? ... ]?`
     *
     * **Examples:**
     * - `priority asc, creationDate desc, price?`
     * Direction one of: [`asc`, `desc`]
     * </details>
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public OrderByOptions $orderBy;

    /**
     * @var string Definition of filters follwing the syntax:
     * <details><summary>Definitions and examples:</summary>
     *
     * `<property> <operator> <value> [ <and|or> <property> <operator> <value> ... ]`
     * Value can be either `numeric`, (e.g. 10 or 10.4231) or `string` (e.g. 'active') or a JSON `array` format (e.g. ['ACTIVE','DELETED']) or `null` (null or 'null')
     *
     * **Examples:**
     * - `price lt 10`
     * - `price ge 10.8 and price le 20 or categoryId eq 12`
     * - `city eq 'Berlin'`
     * - `city in ['Berlin','Paris']`
     * Strings have to be put in quotes `'` If values contain single quotes, they need to be escaped. E.g. `location eq 'llocnou d\'en fenollet'`
     * Supported operators: `lt` lower then, `gt` greater then, `le` lower equal, `ge` greater equal, `eq` equal, `ne` not equal, `in` in and `bw` between.
     * `null`-value supports only `eq` and `ne`, `array`-value supports only `in` and `bw` operators.
     * </details>
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public FiltersOptions $filters;

    /**
     * @var string Definition of expanding options (properties to be loaded alongside with entity) follwing the syntax:
     * <details><summary>Definitions and examples:</summary>
     *
     * `<property> (<expandDefinitions>)?, <property> (<expandDefinitions)? ... ]`
     *   `<expandDefinitions>` is defined as:
     *     `<expandDefinition>,<expandDefinition>, ...`
     *      `<expandDefinition>` is defined as:
     *         `<filterDefinitions>` or `<orderByDefinitions>` or `<topDefinition>` or `<skipDefinition>` or `<expandDefinition>`
     *
     * *Examples:*
     * - `openingHours, competitors`
     * - `openingHours, competitors(filters=type eq 'GOOGLE';orderBy=KWS desc;top=10;skip=20)`
     * - `projects(expand=business(expand=locations(expand=website)))`
     * </details>
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ExpandOptions $expand;

    /**
     * Populates data from current request to dto
     * @param Request $request
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function setPropertiesFromRequest(Request $request): void
    {
        parent::setPropertiesFromRequest($request);
        $dtoQueryOptionsAttributeInstane = static::getDtoQueryOptions();
        $queryOptions = $dtoQueryOptionsAttributeInstane->getQueryOptions();

        if (isset($this->expand)) {
            $this->expand->validateAgainstDefinitionsFromReferenceClass($dtoQueryOptionsAttributeInstane->baseEntity);
        }
        if (isset($this->filters)) {
            if ($queryOptions && isset($queryOptions->filtersDefinitions)) {
                $expandOptions = $this->expand ?? null;
                $this->filters->validateAgainstDefinitions($queryOptions->filtersDefinitions, $expandOptions);
            }
        }
        if (isset($this->orderBy)) {
            if ($queryOptions) {
                $orderByDefinitions = $queryOptions->orderByDefinitions ?? [];
                $this->orderBy->validateAgainstDefinitions($orderByDefinitions);
                // set filters definitions for OrderBy options that are based on filters
                // this is relevant for knowning that no alias is needed when we apply orderBy option to query builder
                if ($queryOptions->filtersDefinitions) {
                    foreach ($this->orderBy->getElements() as $orderByOption) {
                        if ($filterDefinition = $queryOptions->filtersDefinitions->getFilterDefinitionForPropertyName(
                            $orderByOption->propertyName
                        )) {
                            $orderByOption->setFiltersDefinition($filterDefinition);
                        }
                    }
                }
            }
        }
    }

    /**
     * Returns instance of DtoQueryOptions Attribute
     * @return DtoQueryOptions
     * @throws ReflectionException
     */
    public static function getDtoQueryOptions(): DtoQueryOptions
    {
        $reflectionClass = ReflectionClass::instance(static::class);
        /** @var DtoQueryOptions|null $dtoQueryOptionsAttributeInstane */
        $dtoQueryOptionsAttributeInstane = $reflectionClass->getAttributeInstance(
            DtoQueryOptions::class
        );
        return $dtoQueryOptionsAttributeInstane;
    }

    /**
     * Returns instance of QueryOptions Attribute
     * @return FiltersDefinitions|null
     * @throws ReflectionException
     */
    public static function getQueryOptions(): ?QueryOptions
    {
        return static::getDtoQueryOptions()?->getQueryOptions();
    }

    /**
     * Returns filters if present
     * @return FiltersOptions|null
     */
    public function getFilters(): ?FiltersOptions
    {
        return $this?->filters;
    }

    /**
     * Returns pagination limimt
     * @return int
     */
    public function getTop(): int
    {
        return $this->top;
    }

    public function getSkip(): int
    {
        return $this->skip;
    }
}