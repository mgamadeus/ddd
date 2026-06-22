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
use DDD\Presentation\Base\OpenApi\Exceptions\TypeDefinitionMissingOrWrong;
use ReflectionException;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

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

    // Grammar documented ONCE per surface via QueryOptionsSyntax; documenters reset this param's description to the
    // short summary + the endpoint's sortable list, so this docblock is IDE-only (not rendered per parameter).
    /** @var string OrderBy Options (OData-inspired). Syntax: see "QueryOptions syntax". */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public OrderByOptions $orderBy;

    // Grammar (operators, value rules, examples) documented ONCE per surface via QueryOptionsSyntax; documenters reset
    // this param's description to the short summary + the endpoint's allowed-filter list, so this docblock is IDE-only.
    /** @var string Filter Options (OData-inspired). Syntax: see "QueryOptions syntax". */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public FiltersOptions $filters;

    // Grammar (clauses, recursion, examples) documented ONCE per surface via QueryOptionsSyntax; documenters reset this
    // param's description to the short summary + the endpoint's expandable-relations list, so this docblock is IDE-only.
    /** @var string Expand Options (OData-inspired). Syntax: see "QueryOptions syntax". */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ExpandOptions $expand;

    // NOTE: unlike the other three, `select` has NO reset branch in the documenters — THIS docblock IS the rendered
    // parameter description for the agent. Keep the trigger phrase ("Select Options (OData-inspired)" + "QueryOptions
    // syntax") so it matches the once-emitted grammar block verbatim. Full grammar lives in QueryOptionsSyntax.
    /** @var string Select Options (OData-inspired). Syntax: see "QueryOptions syntax". */
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
        /**
         * This `parent::` is intentional and load-bearing: a using class always extends
         * RequestDto (the base type), so `parent::setPropertiesFromRequest()` chains the
         * base population pass before the QueryOptions validation below. PhpStorm flags
         * this because parent-resolution happens at the using class, not at the trait
         * itself, hence the @noinspection.
         * @noinspection PhpUndefinedMethodInspection
         */
        parent::setPropertiesFromRequest($request);

        $dtoQueryOptionsAttributeInstance = static::getDtoQueryOptions();
        if ($dtoQueryOptionsAttributeInstance === null) {
            throw new TypeDefinitionMissingOrWrong(
                'Class ' . static::class . ' uses DtoQueryOptionsTrait (QueryOptions) but has no #['
                . DtoQueryOptions::class . '] attribute on the class or any parent — '
                . 'add the attribute (with its baseEntity) or remove the trait.'
            );
        }

        try {
            $queryOptions = $dtoQueryOptionsAttributeInstance->getQueryOptions();
        } catch (Throwable $e) {
            throw new TypeDefinitionMissingOrWrong(
                'Failed to resolve QueryOptions for ' . static::class . ' from its #['
                . DtoQueryOptions::class . '] attribute: ' . $e->getMessage()
            );
        }

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
                $expandOptions = $this->expand ?? null;
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
     *
     * Walks the class hierarchy so a `#[DtoQueryOptions]` placed on a base DTO is picked
     * up by subclasses (PHP attributes aren't reflection-inherited by default). Returns
     * null when no class in the hierarchy declares the attribute — callers must handle
     * null and throw a {@see TypeDefinitionMissingOrWrong} naming the offending class.
     *
     * @return DtoQueryOptions|null
     * @throws ReflectionException
     */
    public static function getDtoQueryOptions(): ?DtoQueryOptions
    {
        $className = static::class;
        while ($className !== false) {
            /** @var DtoQueryOptions|null $dtoQueryOptionsAttributeInstance */
            $dtoQueryOptionsAttributeInstance = ReflectionClass::instance($className)
                ->getAttributeInstance(DtoQueryOptions::class);
            if ($dtoQueryOptionsAttributeInstance !== null) {
                return $dtoQueryOptionsAttributeInstance;
            }
            $className = get_parent_class($className);
        }
        return null;
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
