<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\QueryOptions;

use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
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
     * @var string
     * OrderBy Options (OData-inspired)
     *
     * Comma-separated list of sort keys:
     * `<propertyPath> <direction>?, <propertyPath> <direction>? ...`
     *
      * Rules / restrictions:
      * - `<propertyPath>` is **not quoted** and supports dot-notation.
      * - Sortable fields are **endpoint-specific** and validated against the endpoint QueryOptions definitions.
      * - Sorting by expanded relations is supported via `<relation>.<field>` if that relation is included via `expand`.
      * - `<direction>` is optional (defaults to `asc`), allowed: `asc`, `desc` (case-insensitive).
      * - Fulltext relevance score ordering is supported via the `{propertyName}Score` suffix (e.g. `nameScore desc`).
      *   This requires a corresponding fulltext filter on the base property using `ft` or `fb`.
      * - Score ordering also works on expanded relations using dot-notation (e.g. `business.nameScore desc`),
      *   but requires:
      *   - the relation to be included via `expand=business`
      *   - a fulltext filter on the matching base property (e.g. `filters=business.name ft 'kfc arad'`)
      *
      * @example someField asc, otherField desc
      * @example someRelation.someField desc
      * @example nameScore desc
      * @example business.nameScore desc
      */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public OrderByOptions $orderBy;

    /**
     * @var string
     * Filter Options (OData-inspired)
     * Basics:
     * - Combine comparisons with `and` / `or` (case-insensitive).
     * - Use parentheses `(...)` for grouping / precedence (nesting allowed).
     *
     * Comparison:
     * - `<property> <operator> <value>`
     * - `<property>` may use dot-notation: `foo`, `fooBar`, `foo.bar_baz`
     *
      * Operators:
      * - `eq`, `ne`, `gt`, `ge`, `lt`, `le`  -> single scalar value
      * - `in`                                -> list of scalars: `['A','B']`
      * - `bw`                                -> list of exactly 2 scalars: `['from','to']`
      * - `ft`                                -> fulltext search (MATCH AGAINST) in natural language mode
      * - `fb`                                -> fulltext search (MATCH AGAINST) in boolean mode
      *
      * Fulltext notes:
      * - `ft` / `fb` require that the target column is FULLTEXT-indexed in the database.
      * - For `#[Translatable(fullTextIndex: true)]` properties, the system uses a generated stored virtual
      *   search column (e.g. `virtualNameSearch`) behind the scenes, so filtering still targets `name` in
      *   the API while the database query targets the virtual FULLTEXT column.
      *
      * Value rules (strict):
      * - Every scalar MUST be wrapped in single quotes: `'...'` (numbers and dates included).
      * - NULL MUST be written as the scalar `'NULL'`.
      * - Lists MUST use brackets and comma separation, with every item quoted: `['A','B']`.
     *
     * @example someInt lt '10'
      * @example someDate ge '2025-01-01'
      * @example someEntity.subEntity.someProperty eq 'mydomain.com'
      * @example someStatus in ['UPCOMING','RUNNING']
      * @example someDate bw ['2026-01-01','2026-01-22']
      * @example name ft 'berlin brandenburg'
      * @example name fb '+berlin +brandenburg -potsdam'
      * @example business.name ft 'kfc arad'
      * @example someDate eq 'NULL' or someDate gt '2025-01-01'
      * @example (someStatus in ['UPCOMING','RUNNING'] and someInt ge '2') or otherInt eq '42'
      * @example (someId eq '1' and ((someStartDate le '2026-01-22' and someEndDate ge '2026-01-01') or (someStartDate bw ['2026-01-01','2026-01-22'] or someEndDate bw ['2026-01-01','2026-01-22'])))
      */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public FiltersOptions $filters;

    /**
     * @var string
     * Expand Options (OData-inspired)
     *
     * Comma-separated list of relations to include:
     * `someRelation,otherRelation`
     *
     * A relation may define clauses in parentheses. **Clauses are separated by semicolons (`;`)**:
     * `someRelation(select=someField,otherField;filters=...;orderBy=someField desc;top=50;skip=0;expand=otherRelation(select=someField))`
     *
     * Supported clauses (inside `(...)`):
     * - `select=<prop>,<prop>,...` (comma-separated list, no quotes)
     * - `filters=<filterExpr>` (same language as `filters`)
     * - `orderBy=<propertyPath> <direction>?` (same language as `orderBy`)
     * - `top=<int>`
     * - `skip=<int>`
     * - `expand=<relation>[...],...` (comma-separated, recursive)
     *
     * Rules / restrictions:
     * - Expandable relations and selectable/orderable fields are **endpoint-specific** and validated against the endpoint QueryOptions definitions.
     * - `and` / `or` inside `filters` are case-insensitive.
     *
     * @example someRelation,otherRelation
     * @example someRelation(select=someField,otherField)
     * @example someRelation(expand=otherRelation(select=someField))
     * @example someRelation(orderBy=someField desc;top=50;skip=0;select=someField,otherField;expand=otherRelation(select=someField))
     */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ExpandOptions $expand;

    /**
     * @var string
     * Select Options (OData-inspired)
     *
     * Comma-separated list of properties to include in the response.
     *
     * Rules / restrictions:
     * - Properties are **not quoted**.
     * - Whitespace around commas is ignored.
     * - Dot-notation is allowed (e.g. `nested.fieldName`).
     * - Selectable fields are **endpoint-specific** and validated against the endpoint QueryOptions definitions.
     * - Not allowed: operators (`eq`, `gt`, ...), lists (`[...]`), parentheses, or any functions.
     *
     * @example id,name
     * @example scope,identifier,type,publicUrl
     * @example nested.fieldName,nested.otherField
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
                $expandOptions = isset($this->expand) ? $this->expand : null;
                $this->orderBy->validateAgainstDefinitions($queryOptions->getOrderByDefinitions(), $expandOptions);
                // Set filters definitions for OrderBy options based on filters.
                if ($queryOptions->getFiltersDefinitions()) {
                    foreach ($this->orderBy->getElements() as $orderByOption) {
                        if (
                            $filterDefinition = $queryOptions->getFiltersDefinitions()->getFilterDefinitionForPropertyName(
                                $orderByOption->propertyName
                            )
                        ) {
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
                    if (
                        $filterDefinition = $queryOptions->getFiltersDefinitions()->getFilterDefinitionForPropertyName(
                            $selectOption->propertyName
                        )
                    ) {
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
