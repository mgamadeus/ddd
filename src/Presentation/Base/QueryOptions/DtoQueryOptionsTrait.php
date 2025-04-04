<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\QueryOptions;

use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\SelectOptions;
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
    public ?int $skip = null;

    /** @var int The number of results to be returned */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $top = null;

    /** @var string|null Cursor for point to a resultset that was previously provided */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $skiptoken = null;

    /**
     * @var string Definition of orderBy following the syntax:
     * <details><summary>Definitions and examples:</summary>
     *
     * `<property> <direction>?, <property> <direction>? ...`
     *
     * **Examples:**
     * - `priority asc, creationDate desc, price`
     * </details>
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public OrderByOptions $orderBy;

    /**
     * @var string Definition of filters following the syntax:
     * <details><summary>Definitions and examples:</summary>
     *
     * `<property> <operator> <value> [ <and|or> <property> <operator> <value> ... ]`
     *
     * **Examples:**
     * - `price lt 10`
     * - `city eq 'Berlin'`
     * </details>
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public FiltersOptions $filters;

    /**
     * @var string Definition of expanding options following the syntax:
     * <details><summary>Definitions and examples:</summary>
     *
     * `<property> (<expandDefinitions>)?, <property> (<expandDefinitions>)? ...`
     *
     * **Examples:**
     * - `openingHours, competitors`
     * - `projects(expand=business(expand=locations(expand=website)))`
     * </details>
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ExpandOptions $expand;

    /**
     * @var string Definition of select options following the syntax:
     * <details><summary>Definitions and examples:</summary>
     *
     * `<property>, <property>, ...`
     *
     * **Examples:**
     * - `name, email, phone`
     * </details>
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public SelectOptions $select;

    /**
     * Populates data from current request to dto.
     * @param Request $request
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function setPropertiesFromRequest(Request $request): void
    {
        parent::setPropertiesFromRequest($request);
        $dtoQueryOptionsAttributeInstance = static::getDtoQueryOptions();
        $queryOptions = $dtoQueryOptionsAttributeInstance->getQueryOptions();

        if (isset($this->expand)) {
            $this->expand->validateAgainstDefinitionsFromReferenceClass($dtoQueryOptionsAttributeInstance->baseEntity);
        }
        if (isset($this->filters)) {
            if ($queryOptions && $queryOptions->getFiltersDefinitions()) {
                $expandOptions = $this->expand ?? null;
                $this->filters->validateAgainstDefinitions($queryOptions->getFiltersDefinitions(), $expandOptions);
            }
        }
        if (isset($this->orderBy)) {
            if ($queryOptions) {
                $this->orderBy->validateAgainstDefinitions($queryOptions->getOrderByDefinitions());
                // Set filters definitions for OrderBy options based on filters.
                if ($queryOptions->getFiltersDefinitions()) {
                    foreach ($this->orderBy->getElements() as $orderByOption) {
                        if ($filterDefinition = $queryOptions->getFiltersDefinitions()->getFilterDefinitionForPropertyName(
                            $orderByOption->propertyName
                        )) {
                            $orderByOption->setFiltersDefinition($filterDefinition);
                        }
                    }
                }
            }
        }
        if (isset($this->select)) {
            if ($queryOptions && $queryOptions->getFiltersDefinitions()) {
                // Set filters definitions for Select options based on filters.
                foreach ($this->select->getElements() as $selectOption) {
                    if ($filterDefinition = $queryOptions->getFiltersDefinitions()->getFilterDefinitionForPropertyName(
                        $selectOption->propertyName
                    )) {
                        $selectOption->setFiltersDefinition($filterDefinition);
                    }
                }
            }
        }
    }

    /**
     * Returns instance of DtoQueryOptions Attribute.
     * @return DtoQueryOptions
     * @throws ReflectionException
     */
    public static function getDtoQueryOptions(): DtoQueryOptions
    {
        $reflectionClass = ReflectionClass::instance(static::class);
        /** @var DtoQueryOptions|null $dtoQueryOptionsAttributeInstance */
        $dtoQueryOptionsAttributeInstance = $reflectionClass->getAttributeInstance(
            DtoQueryOptions::class
        );
        return $dtoQueryOptionsAttributeInstance;
    }

    /**
     * Returns instance of QueryOptions Attribute.
     * @return QueryOptions|null
     * @throws ReflectionException
     */
    public static function getQueryOptions(): ?QueryOptions
    {
        return static::getDtoQueryOptions()?->getQueryOptions();
    }

    /**
     * Returns filters if present.
     * @return FiltersOptions|null
     */
    public function getFilters(): ?FiltersOptions
    {
        return $this?->filters;
    }

    /**
     * Returns pagination limit.
     * @return int|null
     */
    public function getTop(): ?int
    {
        if (isset($this->top) && $this->top <= 0) {
            $this->top = null;
        }
        return $this->top ?? null;
    }

    public function getSkip(): ?int
    {
        return $this->skip ?? null;
    }

    public function getSkiptoken(): ?int
    {
        return $this->skiptoken ?? null;
    }
}