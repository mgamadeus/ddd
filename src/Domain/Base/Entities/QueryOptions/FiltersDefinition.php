<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\MethodNotAllowedException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use ReflectionException;

/**
 * @method FiltersDefinitions getParent()
 * @property FiltersDefinitions $parent
 */
class FiltersDefinition extends ValueObject
{
    /** @var string The property name the filter will be applied to */
    public ?string $propertyName;

    /** @var string A filter on an instant: its literals are wall-clock readings that need a zone to become a moment */
    public const string TEMPORAL_KIND_MOMENT = 'MOMENT';

    /** @var string A filter on a calendar day: its literals carry no time and are never zone-converted */
    public const string TEMPORAL_KIND_DAY = 'DAY';

    /** @var string|int Options for the property values to be filtered for (optional) */
    public ?array $options;

    /**
     * @var bool Whether the fulltext operators (ft / fb) can work on this property, i.e. the column is covered by a
     * SINGLE-column FULLTEXT index — a property-level #[DatabaseIndex(indexType: DatabaseIndex::TYPE_FULLTEXT)], a
     * class-level one listing exactly this column, or the virtual search column of
     * #[Translatable(fullTextIndex: true)]. Without one the database answers error 1191 ("Can't find FULLTEXT index
     * matching the column list"), so the documentation advertises the operators only where they work. Derived from
     * the ATTRIBUTES: this framework does not manage the schema, so an index that exists in the database without a
     * declaration reads as false here — which is why rejecting on it is opt-in
     * ({@see FiltersOptions::FULLTEXT_STRICT_PARAMETER}) and not the default.
     */
    public bool $supportsFulltext = false;

    /**
     * @var string|null MOMENT for a DateTime-typed or DateTime-meaning filter, DAY for a Date one, null = not
     * temporal. Set automatically from the reflected property type, or explicitly through the associative
     * definition form in {@see FiltersDefinitions::__construct()}.
     */
    public ?string $temporalKind = null;

    /**
     * @var ExpandDefinition|null if this filter is based on an expand property, this is the corresponding ExpandDefinition attached
     */
    protected ?ExpandDefinition $expandDefinition = null;

    public function __construct(string $propertyName = null, array $options = null, ?ExpandDefinition $expandDefinition = null)
    {
        $this->propertyName = $propertyName;
        $this->options = $options;
        $this->expandDefinition = $expandDefinition;
        parent::__construct();
    }

    /**
     * @return ExpandDefinition|null
     */
    public function getExpandDefinition(): ?ExpandDefinition
    {
        return $this->expandDefinition;
    }

    /**
     * @param ExpandDefinition|null $expandDefinition
     */
    public function setExpandDefinition(?ExpandDefinition $expandDefinition): void
    {
        $this->expandDefinition = $expandDefinition;
    }

    public function uniqueKey(): string
    {
        return $this->propertyName;
    }

    /**
     * Returns Reference Class DB Repo (and returns DBEntity in case of reference class being EntitySet)
     * @return string|null
     * @throws MethodNotAllowedException
     * @throws ReflectionException
     */
    public function getReferenceClassRepo(): ?string
    {
        $referenceClass = $this->getReferenceClass();
        if (!$referenceClass) {
            return null;
        }
        $reflectionClass = ReflectionClass::instance($referenceClass);
        if (!$reflectionClass->hasTrait(QueryOptionsTrait::class)) {
            throw new MethodNotAllowedException("Cannot use class $referenceClass as reference class of FiltersOptions as it has no QueryOptions trait.");
        }
        /** @var DefaultObject $referenceClass */
        $repoClass = $referenceClass::getRepoClass(LazyLoadRepo::DB);
        return $repoClass;
    }

    /**
     * Returns reference class of the FitlersDefinition,
     * In case of perent FilterDefinitions has an EntitySet as reference class, returns Entity class instead
     * @return string|null
     */
    public function getReferenceClass(): ?string
    {
        $filtersDefinitions = $this->getParent();
        if (!$filtersDefinitions) {
            return null;
        }
        if (isset($filtersDefinitions->referenceClassName)) {
            $referenceClass = $filtersDefinitions->referenceClassName;
            if (is_a($referenceClass, EntitySet::class, true)) {
                /** @var EntitySet $referenceClass */
                $referenceClass = $referenceClass::getEntityClass();
            }
            return $referenceClass;
        }
        return null;
    }
}