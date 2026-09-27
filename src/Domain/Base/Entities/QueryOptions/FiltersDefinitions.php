<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumn;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionNamedType;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use PHPUnit\TextUI\ReflectionException;
use ReflectionAttribute;
use ReflectionUnionType;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * @property FiltersDefinition[] $elements
 * @method FiltersDefinition[] getElements()
 * @method FiltersDefinition first()
 * @method FiltersDefinition getByUniqueKey(string $uniqueKey)
 */
class FiltersDefinitions extends ObjectSet
{
    /** @var FiltersDefinitions[] */
    protected static ?array $filtersDefinitionsForClass = [];

    /** @var string Reference class on which Definitions are based on */
    public string $referenceClassName;

    /**
     * @var bool If true, current FiltersDefinitions have been set based on allowedPropertyNames in Filters Attribute e.g.
     * #[QueryOptions(filters: [[Post::FILTER_REPOTYPE, LazyLoadRepo::DB, LazyLoadRepo::ARGUS]])]
     * This property is needed in order to be aware if later on filters based on reference class have to be added
     */
    public bool $filtersSetFromAttribute = false;

    /**
     * @var bool If true, current FiltersDefinitions have been set based on referenceClassName
     * This property is needed in order to know if filters from reference class have already been added as if not,
     * we need to add them and merge these with filters from Attribute
     */
    public bool $filtersSetFromReferenceClass = false;

    /**
     * @var array temporalKind by (prefixed) property name, collected while reflecting a reference class and consumed
     * by {@see self::getFiltersDefinitionsForReferenceClass()} right after; the reflection helper's return shape
     * stays untouched because callers rely on it
     */
    protected static array $temporalKindsForCurrentReflection = [];

    /**
     * @var array Property names (prefixed, as registered) whose column carries a single-column FULLTEXT index,
     * collected alongside {@see self::$temporalKindsForCurrentReflection} and consumed in the same place
     */
    protected static array $fulltextCapablePropertiesForCurrentReflection = [];

    /**
     * Allowed filters either as string representing allowed property name or
     * array representing on it's first index the property name and following allwed options to be used as value
     * @param string|array ...$allowedPropertyNames
     */
    public function __construct(string|array ...$allowedPropertyNames)
    {
        foreach ($allowedPropertyNames as $allowedPropertyName) {
            // An ASSOCIATIVE array is the metadata form: every element of a LIST is an allowed VALUE, so metadata
            // can not travel in a positional slot.
            if (is_array($allowedPropertyName) && !array_is_list($allowedPropertyName)) {
                $filtersDefinition = new FiltersDefinition(
                    $allowedPropertyName['propertyName'],
                    $allowedPropertyName['options'] ?? null
                );
                $filtersDefinition->temporalKind = $allowedPropertyName['temporalKind'] ?? null;
                $filtersDefinition->supportsFulltext = (bool)($allowedPropertyName['supportsFulltext'] ?? false);
                $this->add($filtersDefinition);
                continue;
            }
            if (is_array($allowedPropertyName)) {
                $allowedPropertyName = new FiltersDefinition(
                    $allowedPropertyName[0], array_slice($allowedPropertyName, 1)
                );
                $this->add($allowedPropertyName);
            } else {
                $allowedPropertyName = new FiltersDefinition($allowedPropertyName);
                $this->add($allowedPropertyName);
            }
        }
        parent::__construct();
    }

    /**
     * Reteurns FiltersDefinitions based on EntitySet elements class content
     * @param string $referenceClassName
     * @return FiltersDefinitions
     */
    public static function getFiltersDefinitionsForReferenceClass(
        string $referenceClassName,
    ): ?FiltersDefinitions {
        if (self::$filtersDefinitionsForClass[$referenceClassName] ?? null) {
            return self::$filtersDefinitionsForClass[$referenceClassName];
        }
        $filtersDefinitions = new FiltersDefinitions();
        // we only generate filters for all property names if the class has a Lazyload repo of type DB
        /** @var Entity $referenceClassName */
        $repoClass = $referenceClassName::getRepoClass(LazyLoadRepo::DB);
        $reflectionClass = ReflectionClass::instance($referenceClassName);
        if ($repoClass) {
            $filtersDefinitions->referenceClassName = $referenceClassName;
            self::$temporalKindsForCurrentReflection = [];
            self::$fulltextCapablePropertiesForCurrentReflection = [];
            $filtersProperties = self::getFilterPropertiesForClass($referenceClassName);
            $temporalKinds = self::$temporalKindsForCurrentReflection;
            $fulltextCapableProperties = self::$fulltextCapablePropertiesForCurrentReflection;
            self::$temporalKindsForCurrentReflection = [];
            self::$fulltextCapablePropertiesForCurrentReflection = [];
            if ($filtersProperties) {
                foreach ($filtersProperties as $filterPropertyName => $options) {
                    $filterDefinition = new FiltersDefinition(
                        $filterPropertyName, is_array($options) ? $options : null
                    );
                    $filterDefinition->temporalKind = $temporalKinds[$filterPropertyName] ?? null;
                    $filterDefinition->supportsFulltext = $fulltextCapableProperties[$filterPropertyName] ?? false;
                    $filtersDefinitions->add($filterDefinition);
                }
            }
            /*
            $expandDefinitions = ExpandDefinitions::getExpandDefinitionsForReferenceClass(
                $referenceClassName,
                $depth
            );

            foreach ($expandDefinitions->getElements() as $expandDefinition) {
                // check property of expand definition
                if ($expandDefinition->filtersDefinitions ?? null) {
                    foreach ($expandDefinition->filtersDefinitions->getElements() as $filtersDefinition) {
                        $filterDefinition = new FiltersDefinition(
                            $expandDefinition->propertyName . '.' . $filtersDefinition->propertyName,
                            $filtersDefinition->options ?? null,
                            $expandDefinition
                        );
                        $filtersDefinitions->add($filterDefinition);

                        $filtersProperties[$expandDefinition->propertyName . '.' . $filtersDefinition->propertyName] = $filtersDefinition->options ?? true;
                    }
                }
            }*/
        }
        self::$filtersDefinitionsForClass[$referenceClassName] = $filtersDefinitions;
        return $filtersDefinitions;
    }

    /**
     * Recursively determines filter options as associative array by option property name e.g. ['firstName'=>true] and in case of filter having options,
     * ['status'=>['active','inactive']]] including the options as well
     * @param string $className
     * @param string $propertyPrefix
     * @param array $callPath
     * @return array
     * @throws ReflectionException
     */
    protected static function getFilterPropertiesForClass(
        string $className,
        string $propertyPrefix = '',
        array $callPath = []
    ): array {
        if (isset($callPath[$className])) {
            return [];
        }
        $callPath[$className] = true;
        $reflectionClass = ReflectionClass::instance($className);
        $elementsToSkip = ['queryOptions' => true, 'objectType' => true];
        $allowedFilterProperties = [];
        if (is_a($className, EntitySet::class, true)) {
            $arrayType = $reflectionClass->getProperty('elements')->getType()->getArrayType();
            if ($arrayType instanceof ReflectionNamedType) {
                $elementTypeClass = $arrayType->getName();
                if (is_a($elementTypeClass, DefaultObject::class, true)) {
                    return self::getFilterPropertiesForClass($elementTypeClass, $propertyPrefix, $callPath);
                }
            }
            // if we have multiple types as possible array elements,
            // e.g. in case of an EntitySet containing classes involved in single table inheritance scheme
            // like Post, Event, etc. we need to include all properties from all possible classes
            elseif ($arrayType instanceof ReflectionUnionType) {
                foreach ($arrayType->getTypes() as $possibleType) {
                    $elementTypeClass = $possibleType->getName();
                    if (is_a($elementTypeClass, DefaultObject::class, true)) {
                        $allowedFilterPropertiesForPossibleType = self::getFilterPropertiesForClass(
                            $elementTypeClass,
                            $propertyPrefix,
                            $callPath
                        );
                        $allowedFilterProperties = array_merge(
                            $allowedFilterProperties,
                            $allowedFilterPropertiesForPossibleType
                        );
                    }
                }
                return $allowedFilterPropertiesForPossibleType;
            }
            return [];
        }
        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            $type = null;
            if ($reflectionProperty->getType() instanceof ReflectionUnionType) {
                foreach ($reflectionProperty->getType()->getTypes() as $unionType) {
                    if ($unionType) {
                        $type = $unionType;
                        break;
                    }
                }
            } else {
                $type = $reflectionProperty->getType();
            }
            if (!$type) {
                continue;
            }
            if (isset($elementsToSkip[$reflectionProperty->getName()])) {
                continue;
            }
            // #[HideProperty] means callers may not see the value, so it must not be reachable through filters or
            // orderBy either (orderBy and expand definitions derive from these): `password eq 'a*'` (LIKE prefix),
            // lt/gt/bw comparisons or the sort order narrow the result set without the value ever appearing in a
            // response, letting a caller extract a hidden secret step by step.
            if ($reflectionProperty->getAttributeInstance(HideProperty::class)) {
                continue;
            }
            if ($type->isBuiltin() || (is_a($type->getName(), DateTime::class, true))) {
                /** @var DatabaseColumn $databaseColumnAttribute */
                $databaseColumnAttribute = $reflectionProperty->getAttributeInstance(DatabaseColumn::class);
                /** @var DatabaseVirtualColumn $databaseVirtualColumnAttribute */
                $databaseVirtualColumnAttribute = $reflectionProperty->getAttributeInstance(
                    DatabaseVirtualColumn::class
                );
                /** @var Choice $choiceAttribute */
                $choiceAttribute = $reflectionProperty->getAttributeInstance(Choice::class);
                $propertyName = $propertyPrefix . $reflectionProperty->getName();
                $allowedPropertyValue = $choiceAttribute ? $choiceAttribute->choices : true;

                if ($databaseVirtualColumnAttribute) {
                    $allowedFilterProperties[$propertyPrefix . DatabaseVirtualColumn::getVirtualColumnName(
                        $reflectionProperty->getName()
                    )] = $allowedPropertyValue;
                }

                // Translatable properties with fullTextIndex generate a stored virtual search column
                // e.g. #[Translatable(fullTextIndex: true)] on $name => virtualNameSearch
                /** @var Translatable|null $translatableAttribute */
                $translatableAttribute = $reflectionProperty->getAttributeInstance(Translatable::class);
                if ($translatableAttribute && $translatableAttribute->fullTextIndex) {
                    $fullTextSearchColumnName = $propertyPrefix . Translatable::getFullTextSearchVirtualColumnName(
                        $reflectionProperty->getName()
                    );
                    $allowedFilterProperties[$fullTextSearchColumnName] = $allowedPropertyValue;
                    // both names work with ft/fb: the generated column itself, and the property, which
                    // FiltersOptions rewrites onto that column
                    self::$fulltextCapablePropertiesForCurrentReflection[$fullTextSearchColumnName] = true;
                    self::$fulltextCapablePropertiesForCurrentReflection[$propertyName] = true;
                }

                if ($databaseColumnAttribute && $databaseColumnAttribute->ignoreProperty) {
                    continue;
                }
                $allowedFilterProperties[$propertyPrefix . $reflectionProperty->getName()] = $allowedPropertyValue;
                if (!$type->isBuiltin() && is_a($type->getName(), DateTime::class, true)) {
                    // a calendar day never carries a time, so it is never zone-converted
                    self::$temporalKindsForCurrentReflection[$propertyName] = is_a($type->getName(), Date::class, true)
                        ? FiltersDefinition::TEMPORAL_KIND_DAY
                        : FiltersDefinition::TEMPORAL_KIND_MOMENT;
                }
                if (self::propertyHasSingleColumnFulltextIndex($reflectionClass, $reflectionProperty)) {
                    self::$fulltextCapablePropertiesForCurrentReflection[$propertyName] = true;
                }
            }
            $subObjectFilters = [];
            // for properties, we do not include ObjectSets in filter options
            if ($type instanceof \ReflectionNamedType) {
                if (
                    is_a(
                        $type->getName(),
                        ChangeHistory::class,
                        true
                    )
                ) {
                    /** @var ChangeHistoryTrait $className */
                    $changeHistoryAttributeInstance = $className::getChangeHistoryAttribute(true);
                    $createdColumn = $changeHistoryAttributeInstance?->getCreatedColumn();
                    $modifiedColumn = $changeHistoryAttributeInstance?->getModifiedColumn();
                    if ($createdColumn) {
                        $allowedFilterProperties[$propertyPrefix . $createdColumn] = true;
                        // ChangeHistory writes DateTime values (createdTime / modifiedTime), so these columns are
                        // MOMENT filters like any other reflected DateTime property — they are added here by hand
                        // rather than through the property loop, so the temporal kind has to be recorded here too.
                        self::$temporalKindsForCurrentReflection[$propertyPrefix . $createdColumn] =
                            FiltersDefinition::TEMPORAL_KIND_MOMENT;
                    }
                    if ($modifiedColumn) {
                        $allowedFilterProperties[$propertyPrefix . $modifiedColumn] = true;
                        self::$temporalKindsForCurrentReflection[$propertyPrefix . $modifiedColumn] =
                            FiltersDefinition::TEMPORAL_KIND_MOMENT;
                    }
                }
                // A ValueObject is JSON-inlined in its parent row and select-controlled, NOT a navigable relation: its
                // sub-fields are NOT filter/orderBy properties. Do NOT descend into a VO (e.g. MediaItem.settings would
                // otherwise explode into settings.uberall.createdDateTime, settings.google.status, … per platform).
                // orderBy definitions derive from these filter definitions (AppliedQueryOptions::getOrderByDefinitions),
                // so skipping the VO descent here keeps VO sub-fields out of BOTH filter and orderBy. ObjectSets stay
                // excluded (the "we do not include ObjectSets" rule above); Entities keep their guarded recursion below.
                elseif (DefaultObject::isEntity($type)) {
                    // we do not add lazyloaded Entities as filters
                    if ($lazyloadAttribute = $reflectionProperty->getAttributes(LazyLoad::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null) {
                        continue;
                    }
                    $subObjectFilters = self::getFilterPropertiesForClass(
                        $type->getName(),
                        $propertyPrefix . $reflectionProperty->getName() . '.',
                        $callPath
                    );
                }
            }
            foreach ($subObjectFilters as $subObjectFilter => $details) {
                $allowedFilterProperties[$subObjectFilter] = $details;
            }
        }
        return $allowedFilterProperties;
    }

    /**
     * True when the property's column is covered by a SINGLE-column FULLTEXT index — the only shape the fulltext
     * operators can use: MySQL matches `MATCH(col) AGAINST (…)` against an index on exactly that column list and
     * answers error 1191 otherwise, so a COMPOSITE fulltext index over (a, b) does NOT serve `a ft '…'` and is
     * deliberately not counted here.
     *
     * Reads the declared attributes only (property-level `#[DatabaseIndex]` on this property, or a class-level one
     * whose indexColumns are exactly this column). The framework does not manage the schema, so an index created by
     * hand without a declaration is invisible — see {@see FiltersDefinition::$supportsFulltext}.
     *
     * @param ReflectionClass $reflectionClass
     * @param ReflectionProperty $reflectionProperty
     * @return bool
     */
    protected static function propertyHasSingleColumnFulltextIndex(
        ReflectionClass $reflectionClass,
        ReflectionProperty $reflectionProperty
    ): bool {
        foreach ($reflectionProperty->getAttributes(DatabaseIndex::class, ReflectionAttribute::IS_INSTANCEOF) as $indexAttribute) {
            /** @var DatabaseIndex $indexAttributeInstance */
            $indexAttributeInstance = $indexAttribute->newInstance();
            // a property-level index without an explicit column list means "this column"
            if (
                $indexAttributeInstance->indexType === DatabaseIndex::TYPE_FULLTEXT
                && (
                    $indexAttributeInstance->indexColumns === []
                    || $indexAttributeInstance->indexColumns === [$reflectionProperty->getName()]
                )
            ) {
                return true;
            }
        }
        foreach ($reflectionClass->getAttributes(DatabaseIndex::class, ReflectionAttribute::IS_INSTANCEOF) as $indexAttribute) {
            /** @var DatabaseIndex $indexAttributeInstance */
            $indexAttributeInstance = $indexAttribute->newInstance();
            if (
                $indexAttributeInstance->indexType === DatabaseIndex::TYPE_FULLTEXT
                && $indexAttributeInstance->indexColumns === [$reflectionProperty->getName()]
            ) {
                return true;
            }
        }
        return false;
    }

    public function getFilterDefinitionForPropertyName(string $propertyName): ?FiltersDefinition
    {
        return $this->getByUniqueKey($propertyName);
    }
}