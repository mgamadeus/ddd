<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptionsTrait;

/**
 * Definitions for Query Options Definitions and applied Options such as
 * - filters
 * - orderBy
 * - limit
 * - offset
 * - expand
 * - select
 */
class AppliedQueryOptions extends ValueObject
{
    /** @var int Number of results to be skipped / offsetted */
    public int $skip;

    /** @var int The number of results to be returned */
    public ?int $top;

    /** @var string|null Cursor for point to a resultset that was previously provided */
    public ?string $skiptoken;

    /** @var string|null The class on which the current QueryOptions are derived from */
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

    /** @var SelectOptions|null Applied select options */
    public ?SelectOptions $select = null;

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
     * Sets the current offset and takes care possible limitations.
     * @param int $skip
     * @return AppliedQueryOptions
     */
    public function setSkip(int $skip): AppliedQueryOptions
    {
        $this->skip = max(0, $skip);
        return $this;
    }

    /**
     * Sets the current skiptoken.
     * @param string $skiptoken
     * @return AppliedQueryOptions
     */
    public function setSkiptoken(string $skiptoken): AppliedQueryOptions
    {
        $this->skiptoken = $skiptoken;
        return $this;
    }

    /**
     * Sets limit for number of results returned.
     * @param int $top
     * @return AppliedQueryOptions
     */
    public function setTop(int $top): AppliedQueryOptions
    {
        $this->top = max(1, $top);
        return $this;
    }

    /**
     * Sets filters options to be applied.
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
     * Sets expand options to be applied.
     * @param ExpandOptions $expand
     * @return AppliedQueryOptions
     */
    public function setExpand(?ExpandOptions &$expand = null): AppliedQueryOptions
    {
        $this->expand = $expand;
        return $this;
    }

    /**
     * Sets orderBy options to be applied.
     * @param OrderByOptions $orderBy
     * @return AppliedQueryOptions
     */
    public function setOrderBy(?OrderByOptions &$orderBy = null): AppliedQueryOptions
    {
        $this->orderBy = $orderBy;
        return $this;
    }

    /**
     * Sets select options to be applied.
     * @param SelectOptions $select
     * @return AppliedQueryOptions
     */
    public function setSelect(?SelectOptions &$select = null): AppliedQueryOptions
    {
        $this->select = $select;
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

    public function getSkiptoken(): ?string
    {
        if (!isset($this->skiptoken)) {
            return null;
        }
        return $this->skiptoken;
    }

    public function getExpandOptions(): ?ExpandOptions
    {
        if (!isset($this->expand)) {
            return null;
        }
        return $this->expand;
    }

    public function getSelect(): ?SelectOptions
    {
        return $this->select;
    }

    /**
     * Sets all query options from requestDto such as:
     * - filters
     * - orderBy
     * - limit
     * - offset
     * - expand
     * - select
     * @param RequestDto|DtoQueryOptionsTrait $requestDto
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
        if (isset($requestDto->skiptoken)) {
            $this->setSkiptoken($requestDto->skiptoken);
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
        if (isset($requestDto->select)) {
            $this->setSelect($requestDto->select);
        }
        return $this;
    }

    /**
     * Sets query options from expand option.
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
        if (isset($expandOption->selectOptions)) {
            $this->setSelect($expandOption->selectOptions);
        }
    }

    public function uniqueKey(): string
    {
        $key = ($this->getTop()) . '_' .
            ($this->getSkip()) . '_' .
            (isset($this->orderBy) ? ($this->orderBy?->uniqueKey() ?? '') : '') . '_' .
            (isset($this->filters) ? ($this->filters->uniqueKey() ?? '') : '') . '_' .
            (isset($this->select) ? ($this->select->uniqueKey() ?? '') : '');
        $key = md5($key);
        return self::uniqueKeyStatic($key);
    }

    public function getFiltersDefinitions(): ?FiltersDefinitions
    {
        if (isset($this->filtersDefinitions)) {
            if (isset($this->referenceClass) && !$this->filtersDefinitions->filtersSetFromReferenceClass) {
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
                foreach ($this->filtersDefinitions->getElements() as $filtersDefinition) {
                    $this->orderByDefinitions[] = $filtersDefinition->propertyName;
                }
            }
            return $this->orderByDefinitions;
        }
    }
}