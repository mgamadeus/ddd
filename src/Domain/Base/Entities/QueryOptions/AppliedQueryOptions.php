<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Presentation\Base\Dtos\RequestDto;

/**
 * Definitions for Query Options Definitions and applied Options such as
 * - filters
 * - orderBy
 * - limit
 * - offset
 * - expand
 */
class AppliedQueryOptions extends ValueObject
{
    /** @var int Number of results to be skipped / offsetted */
    public int $skip;

    /** @var int The number of results to be returned */
    public ?int $top;

    public ?string $referenceClass;

    /** @var FiltersDefinitions Allowed filtering property definitions */
    protected ?FiltersDefinitions $filtersDefinitions;

    /** @var string[] Allowed orderBy property definitions */
    protected ?array $orderByDefinitions;

    /** @var FiltersOptions Applied filters options */
    public ?FiltersOptions $filters;

    /** @var ExpandOptions Applied expand options */
    public ?ExpandOptions $expand;

    /** @var OrderByOptions Applied orderBy definitions */
    public ?OrderByOptions $orderBy;

    /** @var int|null The total results, will be populated if available */
    public ?int $totalResults;

    public function __construct(
        QueryOptions $queryOptions = null,
        ?string $referenceClass = null
    ) {
        if (isset($queryOptions->top)) {
            $this->top = $queryOptions->top;
        }
        if (isset($queryOptions->skip)) {
            $this->skip = $queryOptions->skip;
        }
        if (isset($queryOptions->filters) && !empty($queryOptions->filters)) {
            $this->filtersDefinitions = new FiltersDefinitions(...$queryOptions->filters);
            $this->filtersDefinitions->filtersSetFromAttribute = true;
        }
        if (isset($queryOptions->orderBy) && !empty($queryOptions->orderBy)) {
            $this->orderByDefinitions = $queryOptions->orderBy;
        }
        if ($referenceClass) {
            $this->referenceClass = $referenceClass;
        }
        parent::__construct();
    }

    /**
     * Sets the current offset and takes care possible limitations
     * @param int $skip
     * @return AppliedQueryOptions
     */
    public function setSkip(int $skip): AppliedQueryOptions
    {
        $this->skip = max(0, $skip);
        return $this;
    }

    /**
     * Sets limit for number of results returned
     * @param int $top
     * @return AppliedQueryOptions
     */
    public function setTop(int $top): AppliedQueryOptions
    {
        $this->top = max(1, $top);
        return $this;
    }

    /**
     * Sets filters options to be applied
     * @param FiltersOptions $filters
     * @return AppliedQueryOptions
     */
    public function setFilters(?FiltersOptions &$filters = null): AppliedQueryOptions
    {
        $this->filters = $filters;
        // filters need filter definitions also attached to them
        $filtersDefinitions = $this->getFiltersDefinitions();
        if ($filtersDefinitions) {
            $this->filters?->setFiltersDefinitionsForAllFilterOptions($filtersDefinitions);
        }
        return $this;
    }

    /**
     * Sets expand options to be applied
     * @param ExpandOptions $filters
     * @return AppliedQueryOptions
     */
    public function setExpand(?ExpandOptions &$expand = null): AppliedQueryOptions
    {
        $this->expand = $expand;
        return $this;
    }

    /**
     * Sets orderBy options to be applied
     * @param OrderByOptions $orderBy
     * @return AppliedQueryOptions
     */
    public function setOrderBy(?OrderByOptions &$orderBy = null): AppliedQueryOptions
    {
        $this->orderBy = $orderBy;
        return $this;
    }

    public function getOrderBy(): ?OrderByOptions
    {
        if (!isset($this->orderBy)) {
            return null;
        }
        return $this->orderBy;
    }

    public function getFilters(): ?FiltersOptions
    {
        if (!isset($this->filters)) {
            return null;
        }
        return $this->filters;
    }

    public function getTop(): ?int
    {
        if (!isset($this->top)) {
            return null;
        }
        return $this->top;
    }

    public function getSkip(): ?int
    {
        if (!isset($this->skip)) {
            return null;
        }
        return $this->skip;
    }

    public function getExpandOptions(): ?ExpandOptions
    {
        if (!isset($this->expand)) {
            return null;
        }
        return $this->expand;
    }

    /**
     * Sets all query options from requestDto such as:
     * - filters
     * - orderBy
     * - limit
     * - offset
     * - expand
     * @param RequestDto $requestDto
     * @return AppliedQueryOptions
     */
    public function setQueryOptionsFromRequestDto(RequestDto &$requestDto): AppliedQueryOptions
    {
        if (isset($requestDto->skip)) {
            $this->setSkip($requestDto->skip);
        }
        if (isset($requestDto->top)) {
            $this->setTop($requestDto->top);
        }
        if (isset($requestDto->filters)) {
            $this->setFilters($requestDto->filters);
        }
        if (isset($requestDto->orderBy)) {
            $this->setOrderBy($requestDto->orderBy);
        }
        if (isset($requestDto->expand)) {
            $this->setExpand($requestDto->expand);
        }
        return $this;
    }

    /**
     * Sets query options from expand option
     * @param ExpandOption $expandOption
     * @return void
     */
    public function setQueryOptionsFromExpandOption(ExpandOption &$expandOption)
    {
        if (isset($expandOption->top)) {
            $this->setTop($expandOption->top);
        }
        if (isset($expandOption->skip)) {
            $this->setSkip($expandOption->skip);
        }
        if (isset($expandOption->filters)) {
            $this->setFilters($expandOption->filters);
        }
        if (isset($expandOption->orderByOptions)) {
            $this->setOrderBy($expandOption->orderByOptions);
        }
        if (isset($expandOption->expandOptions)) {
            $this->setExpand($expandOption->expandOptions);
        }
    }

    public function uniqueKey(): string
    {
        $key = ($this->getTop()) . '_' . ($this->getSkip(
            )) . '_' . (isset($this->orderBy) ? ($this->orderBy?->uniqueKey(
            ) ?? '') : '') . '_' . (isset($this->filters) ? ($this->filters->uniqueKey() ?? '') : '');
        $key = md5($key);
        return self::uniqueKeyStatic($key);
    }

    public function getFiltersDefinitions(): ?FiltersDefinitions
    {
        if (isset($this->filtersDefinitions)) {
            if (isset($this->referenceClass) && !$this->filtersDefinitions->filtersSetFromReferenceClass){
                $filtersFromReferenceClass = FiltersDefinitions::getFiltersDefinitionsForReferenceClass($this->referenceClass);
                $this->filtersDefinitions->mergeFromOtherSet($filtersFromReferenceClass);
                $this->filtersDefinitions->filtersSetFromReferenceClass = true;
            }
            return $this->filtersDefinitions;
        } elseif (isset($this->referenceClass)) {
            $this->filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass(
                $this->referenceClass
            );
            $this->filtersDefinitions->filtersSetFromReferenceClass = true;
            return $this->filtersDefinitions;
        }
        return null;
    }

    public function getOrderByDefinitions(): ?array
    {
        if (isset($this->orderByDefinitions)) {
            return $this->orderByDefinitions;
        } else {
            $filtersDefinitions = $this->getFiltersDefinitions();
            $this->orderByDefinitions = [];
            if ($filtersDefinitions) {
                $this->orderByDefinitions = [];
                foreach ($this->filtersDefinitions->getElements() as $filtersDefinition) {
                    $this->orderByDefinitions[] = $filtersDefinition->propertyName;
                }
            }
            return $this->orderByDefinitions;
        }
    }
}