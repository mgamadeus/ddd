<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits\Serializer;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Arr;
use DDD\Infrastructure\Libs\Datafilter;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Traits\ReflectorTrait;
use DDD\Infrastructure\Traits\Serializer\Attributes\DontPersistProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\ExposePropertyInsteadOfClass;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\HidePropertyOnSystemSerialization;
use DDD\Infrastructure\Traits\Serializer\Attributes\OverwritePropertyName;
use DDD\Infrastructure\Traits\Serializer\Attributes\Aliases;
use DDD\Infrastructure\Traits\Serializer\Attributes\SerializeInToonFormat;
use Error;
use Exception;
use ReflectionAttribute;
use ReflectionException;
use stdClass;
use Throwable;

trait SerializerTrait
{
    use ReflectorTrait;

    /**
     * @var bool Enables Token-Oriented Object Notation (TOON) serialization for this class.
     *  https://github.com/toon-format/toon
     */
    protected static $toonEnabledSerialization = false;

    /** @var array Tracks unset properties */
    protected $unsetProperties = [];

    /**
     * @var array Properties that will not be exposed to frontend, allows dynamically remove
     * properties vivibility instead of applying HideProperty attribute
     */
    protected $propertiesToHide = [];

    /**
     * @var array<string, string>|null Instance-level TOON column specification.
     * Applied by convertArrayOfObjectsToToon() when this object's elements are emitted in TOON
     * format (activated either via #[SerializeInToonFormat] on the property or downstream
     * mechanisms). Format: <columnAlias> => <dotPathIntoFlattenedItem>. Null means "fall back
     * to the static class-level spec, or to the union-of-all-keys default".
     */
    protected ?array $toonColumnsSpec = null;

    /**
     * @var array<string, bool> Instance-level TOON activation list.
     * Property names listed here are emitted as TOON when their value is an array of objects,
     * even if the property has no #[SerializeInToonFormat] attribute. Mirror of
     * $propertiesToHide on the activation axis.
     */
    protected array $propertiesToSerializeAsToon = [];

    public static function addStaticPropertiesToHide(bool $forCurrentClass = true, string ...$properties): void
    {
        $className = $forCurrentClass ? static::class : self::class;
        foreach ($properties as $property) {
            if (!isset(StaticRegistry::$propertiesToHideOnSerialization[$className])) {
                StaticRegistry::$propertiesToHideOnSerialization[$className] = [];
            }
            StaticRegistry::$propertiesToHideOnSerialization[$className][$property] = true;
        }
    }

    public static function removeStaticPropertiesToHide(bool $forCurrentClass = true, string ...$properties): void
    {
        $className = $forCurrentClass ? static::class : self::class;
        foreach ($properties as $property) {
            if (isset(StaticRegistry::$propertiesToHideOnSerialization[$className][$property])) {
                unset(StaticRegistry::$propertiesToHideOnSerialization[$className][$property]);
            }
        }
    }

    /**
     * clears all marks for given index
     * @param $index
     * @return void
     */
    public static function clearAllMarksForIndex(string $index)
    {
        if (isset(SerializerRegistry::$marks[$index])) {
            unset(SerializerRegistry::$marks[$index]);
        }
    }

    /**
     * Applies hiding spec to this object and delegates to nested objects found via dot-paths.
     *
     * @param array<string, array<int, string>> $map
     */
    public function addPropertiesToHideRecursively(array $map): void
    {
        foreach ($map as $path => $props) {
            // Apply to current object (root of the map)
            if ($path === '') {
                if (!empty($props)) {
                    $this->addPropertiesToHide(...$props);
                }
                continue;
            }

            // Inline path resolution (no helper method)
            $cursor = $this;
            $segments = array_filter(explode('.', $path), static fn($s) => $s !== '');
            foreach ($segments as $seg) {
                // Property must exist and be non-null object to continue descending
                if (!isset($cursor->$seg) || !is_object($cursor->$seg)) {
                    // Stop delegating for this path
                    $cursor = null;
                    break;
                }
                $cursor = $cursor->$seg;
            }

            // Delegate if the final target supports the same API
            if ($cursor !== null && method_exists($cursor, 'addPropertiesToHideRecursively')) {
                $cursor->addPropertiesToHideRecursively(['' => $props]);
            }
        }
    }

    public function addPropertiesToHide(string ...$properties): void
    {
        foreach ($properties as $property) {
            $this->propertiesToHide[$property] = true;
        }
    }

    public function removePropertiesToHide(string ...$properties): void
    {
        foreach ($properties as $property) {
            if (isset($this->propertiesToHide[$property])) {
                unset($this->propertiesToHide[$property]);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // TOON serialization configuration
    //
    // TOON (Token-Oriented Object Notation, https://github.com/toon-format/toon) emits an
    // array of objects as a tabular block: a single header row enumerates column names, then
    // one short row per item. This is dramatically more compact than repeating the JSON keys
    // on every element — useful for high-cardinality property arrays such as GPS track
    // locations, time-series samples, or any uniform record set.
    //
    // Two orthogonal axes of configuration:
    //
    //   1. ACTIVATION  — "should this property's array be emitted as TOON instead of regular
    //                    JSON-array-of-objects?". Activation can be per-property:
    //                      a) declarative via #[SerializeInToonFormat] attribute on the property
    //                      b) imperative class-wide via setStaticPropertiesToSerializeAsToon()
    //                      c) imperative per-instance via addPropertiesToSerializeAsToon()
    //                    All three sources are OR-combined inside toObject(). The emitted
    //                    output property name is the original property name plus the suffix
    //                    SerializeInToonFormat::TOON_PROPERTY_POSTFIX (i.e. "InToonFormat") so
    //                    consumers can distinguish TOON output from regular JSON.
    //
    //   2. COLUMN SPEC — "which inner-object fields appear as columns, in what order, under
    //                    what names?". Two sources, the instance-level wins over class-level:
    //                      a) class-wide via setStaticToonColumnsSpec()
    //                      b) per-instance via setToonColumnsSpec()
    //                    Without an explicit spec, the default behavior is "union of all
    //                    flattened property paths across all rows, in first-seen order".
    //
    // Activation and column spec are independent. You may activate without specifying columns
    // (default columns), or specify columns without activating (no effect — columns spec is
    // only consulted when emission is activated).
    //
    // The spec stored internally is the canonical <columnAlias> => <dotPathIntoFlattenedItem>
    // map. Per-row lookup uses the dot-path key produced by flattenToonColumns() — so
    // 'geoPoint.lat' resolves the nested lat field of a serialized GeoPoint inside the item.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Configure the TOON column spec for THIS instance only. Restricts which inner-object
     * properties appear as columns when this object's elements are serialized to TOON, sets
     * their column order, and optionally renames them.
     *
     * Applies only when TOON emission is also activated for the relevant property — see the
     * activation methods (addPropertiesToSerializeAsToon /
     * setStaticPropertiesToSerializeAsToon / #[SerializeInToonFormat]).
     *
     * Two accepted input formats, distinguished by key type:
     *
     *   List form (numeric keys) — the path string is used as both the column name AND the
     *   lookup key:
     *       $obj->setToonColumnsSpec(['geoPoint.lat', 'geoPoint.lng', 'dateTime']);
     *       // emits columns: geoPoint.lat,geoPoint.lng,dateTime
     *
     *   Map form (string keys) — keys are the emitted column names (aliases), values are
     *   dot-paths into the flattened item:
     *       $obj->setToonColumnsSpec([
     *           'lat' => 'geoPoint.lat',
     *           'lng' => 'geoPoint.lng',
     *           't'   => 'dateTime',
     *       ]);
     *       // emits columns: lat,lng,t
     *
     * Renames are useful when serializing many rows and the per-character cost matters
     * (e.g. 1000 GPS points × saving 8 chars per column header per row = significant payload
     * reduction).
     *
     * Side effect: clears the toObject cache so a subsequent serialization with the new spec
     * does not return a stale cached result.
     *
     * @param array<int|string, string> $columns either a list of paths or a map alias => path
     * @return void
     */
    public function setToonColumnsSpec(array $columns): void
    {
        $this->toonColumnsSpec = self::normalizeToonColumnsSpec($columns);
        SerializerRegistry::$toOjectCache = [];
    }

    /**
     * Remove this instance's TOON column spec. After this call, getToonColumnsSpec() falls
     * back to the static class-level spec (if any), or returns null (column-union default).
     *
     * Side effect: clears the toObject cache to avoid stale cached results.
     *
     * @return void
     */
    public function clearToonColumnsSpec(): void
    {
        $this->toonColumnsSpec = null;
        SerializerRegistry::$toOjectCache = [];
    }

    /**
     * Configure the TOON column spec class-wide. Every instance of the class (and any
     * subclass that does not override the spec on its own class entry) uses the configured
     * column layout for TOON emission, for the lifetime of the request.
     *
     * Use this when the same compact representation should be used everywhere — e.g.
     * TrackLocations always emits its elements with a known short-key column set, regardless
     * of which controller called it.
     *
     * Per-instance setToonColumnsSpec() overrides the static spec for that instance.
     *
     * Format options for $columns are identical to setToonColumnsSpec() — list form (paths
     * doubled as column names) or map form (alias => path).
     *
     * @param bool                       $forCurrentClass true → register under static::class
     *                                                    (the concrete class invoking this);
     *                                                    false → register under self::class
     *                                                    (the class that physically declares
     *                                                    this method, useful when a base
     *                                                    class wants to set a default spec
     *                                                    that subclasses inherit)
     * @param array<int|string, string>  $columns         the column spec to register
     * @return void
     */
    public static function setStaticToonColumnsSpec(bool $forCurrentClass = true, array $columns = []): void
    {
        $className = $forCurrentClass ? static::class : self::class;
        StaticRegistry::$toonColumnsSpecByClass[$className] = self::normalizeToonColumnsSpec($columns);
    }

    /**
     * Remove the class-wide TOON column spec. Instances of this class fall back to the
     * column-union default unless they have an instance-level spec set via
     * setToonColumnsSpec().
     *
     * @param bool $forCurrentClass true → static::class, false → self::class. Same semantics
     *                              as setStaticToonColumnsSpec().
     * @return void
     */
    public static function clearStaticToonColumnsSpec(bool $forCurrentClass = true): void
    {
        $className = $forCurrentClass ? static::class : self::class;
        if (isset(StaticRegistry::$toonColumnsSpecByClass[$className])) {
            unset(StaticRegistry::$toonColumnsSpecByClass[$className]);
        }
    }

    /**
     * Resolve the active TOON column spec for the current object. Resolution order:
     *
     *   1. Instance-level $toonColumnsSpec (set via setToonColumnsSpec())
     *   2. Static class-level under static::class (set via setStaticToonColumnsSpec(true))
     *   3. Static class-level under self::class  (set via setStaticToonColumnsSpec(false))
     *   4. null (column-union default)
     *
     * Called by convertArrayOfObjectsToToon() during serialization; consumers normally don't
     * need to invoke this directly. Useful for diagnostic / debug output and for tests.
     *
     * @return array<string, string>|null canonical map <columnAlias> => <dotPath>, or null
     */
    public function getToonColumnsSpec(): ?array
    {
        if ($this->toonColumnsSpec !== null) {
            return $this->toonColumnsSpec;
        }
        if (isset(StaticRegistry::$toonColumnsSpecByClass[static::class])) {
            return StaticRegistry::$toonColumnsSpecByClass[static::class];
        }
        if (isset(StaticRegistry::$toonColumnsSpecByClass[self::class])) {
            return StaticRegistry::$toonColumnsSpecByClass[self::class];
        }
        return null;
    }

    /**
     * Normalize a user-supplied column spec into the canonical <alias> => <path> map form.
     * List entries (numeric keys) are converted to map entries by reusing the path as both
     * alias and lookup key. Map entries (string keys) are kept as-is.
     *
     * @internal Used by setToonColumnsSpec() and setStaticToonColumnsSpec().
     * @param array<int|string, string> $columns
     * @return array<string, string>
     */
    protected static function normalizeToonColumnsSpec(array $columns): array
    {
        $normalized = [];
        foreach ($columns as $key => $value) {
            if (is_int($key)) {
                $normalized[$value] = $value;
            } else {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }

    // ─── TOON activation (axis 1: which property arrays should be emitted as TOON) ──────────

    /**
     * Activate TOON serialization for one or more properties on THIS instance only. Property
     * arrays listed here are emitted as a TOON tabular block by toObject(), even when no
     * #[SerializeInToonFormat] attribute is declared on the property.
     *
     * The emitted JSON property name is the original property name plus the suffix
     * SerializeInToonFormat::TOON_PROPERTY_POSTFIX (i.e. "elements" → "elementsInToonFormat"),
     * matching the suffixing rule applied by the attribute-driven path. Consumers parsing the
     * response can detect TOON-encoded payloads by the suffix.
     *
     * Use this when the activation is request-specific — for example, a GET endpoint accepts
     * a query flag like `?trackLocationsAsToon=1` and the controller wants to enable TOON
     * only for that response without side-effects on other callers.
     *
     * Combine with setToonColumnsSpec() to additionally control which columns are emitted.
     *
     * Side effect: clears the toObject cache so a subsequent serialization picks up the new
     * activation state.
     *
     * @param string ...$properties one or more public property names of this object
     * @return void
     */
    public function addPropertiesToSerializeAsToon(string ...$properties): void
    {
        foreach ($properties as $property) {
            $this->propertiesToSerializeAsToon[$property] = true;
        }
        SerializerRegistry::$toOjectCache = [];
    }

    /**
     * Deactivate TOON serialization for the listed properties on THIS instance. Properties
     * not currently activated are silently ignored. Class-wide activation registered via
     * setStaticPropertiesToSerializeAsToon() is unaffected by this call.
     *
     * Side effect: clears the toObject cache.
     *
     * @param string ...$properties
     * @return void
     */
    public function removePropertiesToSerializeAsToon(string ...$properties): void
    {
        foreach ($properties as $property) {
            if (isset($this->propertiesToSerializeAsToon[$property])) {
                unset($this->propertiesToSerializeAsToon[$property]);
            }
        }
        SerializerRegistry::$toOjectCache = [];
    }

    /**
     * Activate TOON serialization for one or more properties class-wide. Every instance of
     * the class will emit those property arrays as TOON for the lifetime of the request,
     * without needing #[SerializeInToonFormat] on the property.
     *
     * Typical use: a domain class such as TrackLocations always serializes its elements as
     * TOON for size reasons. Register once at bootstrap (or alongside setStaticToonColumnsSpec
     * in a class-level static-init block) and the behavior is consistent across all callers.
     *
     * @param bool   $forCurrentClass true → register under static::class (the concrete class
     *                                invoking this); false → register under self::class
     *                                (useful when a base class wants to activate a property
     *                                on all subclasses by default)
     * @param string ...$properties   property names to mark as TOON-emitted
     * @return void
     */
    public static function addStaticPropertiesToSerializeAsToon(
        bool $forCurrentClass = true,
        string ...$properties
    ): void {
        $className = $forCurrentClass ? static::class : self::class;
        foreach ($properties as $property) {
            if (!isset(StaticRegistry::$propertiesToSerializeAsToonOnSerialization[$className])) {
                StaticRegistry::$propertiesToSerializeAsToonOnSerialization[$className] = [];
            }
            StaticRegistry::$propertiesToSerializeAsToonOnSerialization[$className][$property] = true;
        }
    }

    /**
     * Remove a class-wide TOON activation. Properties not currently registered are silently
     * ignored. Per-instance activations registered via addPropertiesToSerializeAsToon() are
     * unaffected. The #[SerializeInToonFormat] attribute on the property (if any) is also
     * unaffected — the attribute is declarative on the class definition itself and cannot be
     * removed at runtime.
     *
     * @param bool   $forCurrentClass true → static::class, false → self::class
     * @param string ...$properties
     * @return void
     */
    public static function removeStaticPropertiesToSerializeAsToon(
        bool $forCurrentClass = true,
        string ...$properties
    ): void {
        $className = $forCurrentClass ? static::class : self::class;
        foreach ($properties as $property) {
            if (isset(StaticRegistry::$propertiesToSerializeAsToonOnSerialization[$className][$property])) {
                unset(StaticRegistry::$propertiesToSerializeAsToonOnSerialization[$className][$property]);
            }
        }
    }

    /**
     * Check whether TOON serialization is activated for the given property on the current
     * object via the imperative registries (instance-level or static class-level). Does NOT
     * consider the #[SerializeInToonFormat] attribute — that is checked separately by
     * toObject() via reflection.
     *
     * Resolution order:
     *   1. Instance-level $propertiesToSerializeAsToon
     *   2. Static class-level under static::class
     *   3. Static class-level under self::class
     *
     * Called by toObject() to OR-combine with the attribute check; consumers normally don't
     * need to invoke this directly. Useful for diagnostic / debug output and tests.
     *
     * @param string $propertyName
     * @return bool
     */
    public function isPropertySerializedAsToon(string $propertyName): bool
    {
        if (isset($this->propertiesToSerializeAsToon[$propertyName])) {
            return true;
        }
        if (isset(StaticRegistry::$propertiesToSerializeAsToonOnSerialization[static::class][$propertyName])) {
            return true;
        }
        if (isset(StaticRegistry::$propertiesToSerializeAsToonOnSerialization[self::class][$propertyName])) {
            return true;
        }
        return false;
    }

    /**
     * Recursively converts current entity to a stdClass object: top level entry function,
     * calls processPropertyForSerialization on properties
     *
     * @param bool $cached Whether to use caching
     * @param bool $returnUniqueKeyInsteadOfContent Return unique key for entities instead of full content
     * @param array $path Recursion path tracking
     * @param bool $ignoreHideAttributes Ignore HideProperty attributes
     * @param bool $ignoreNullValues Ignore properties with null values
     * @param bool $forPersistence Include properties for persistence
     * @param int $flags Bitwise flags from Serializer class (default: 0)
     * @return array|stdClass
     * @throws ReflectionException
     */
    public function toObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true,
        int $flags = 0
    ): mixed
    {
        $this->onToObject(
            $cached,
            $returnUniqueKeyInsteadOfContent,
            $path,
            $ignoreHideAttributes,
            $ignoreNullValues,
            $forPersistence,
            $flags
        );
        // in order to avoid caching objects, manipulating them in the meantime and then having an outdated cache, on the first call
        // we clear the SerializerRegistry. Currently this is deactivated, if problems occur, it can be activated again
        /*if (empty($path)){
            SerializerRegistry::clearToObjectCache();
        }*/
        $objectId = spl_object_id($this);
        $cacheKey = $objectId . '_' . $ignoreHideAttributes . '_' . $ignoreNullValues . '_' . $forPersistence . '_' . $flags . '_(' . implode(
                '_',
                array_keys($this->propertiesToHide)
            ) . ')';
        $entityId = DefaultObject::isEntity($this) && isset($this->id) ? static::class . '_' . $this->id : null;
        if ($cached) {
            if ($cachedResult = SerializerRegistry::getToObjectCacheForObjectId($cacheKey)) {
                return $cachedResult;
            }
            /* This creates problems
            if ($entityId) {
                if ($cachedResult = SerializerRegistry::getToObjectCacheForObjectId($entityId)) {
                    return $cachedResult;
                }
            }*/
        }

        // Special handling for ObjectSet when SERIALIZE_ELEMENTS_AS_ARRAY_IN_OBJECT_SETS flag is set
        if ($this instanceof ObjectSet && Serializer::hasFlag(
                $flags,
                Serializer::SERIALIZE_ELEMENTS_AS_ARRAY_IN_OBJECT_SETS
            )) {
            // Return empty array if elements not set
            if (!isset($this->elements)) {
                $result = [];
            } else {
                // Serialize only the elements property
                $propertyValue = $this->elements;
                $result = $this->serializeProperty(
                    $propertyValue,
                    $cached,
                    $returnUniqueKeyInsteadOfContent,
                    $path,
                    $ignoreHideAttributes,
                    $ignoreNullValues,
                    $forPersistence,
                    $flags
                );
            }

            // Store in cache before returning
            if ($cached) {
                SerializerRegistry::setToObjectCacheForObjectId($cacheKey, $result);
            }

            return $result;
        }

        // on each iteraton we add the current spl_obejct_hash to the path. when trying to add the same id again,
        $path[$objectId] = true;
        if ($entityId) {
            $path[$entityId] = true;
        }
        // this means we are in an recursion loop and have to skip

        $resultArray = [];

        $propertyNameToExposeInsteadOfClass = null;
        $reflectionClass = new ReflectionClass($this);
        foreach (
            $reflectionClass->getAttributes(
                ExposePropertyInsteadOfClass::class,
                ReflectionAttribute::IS_INSTANCEOF
            ) as $classAttribute
        ) {
            $attributeInstance = $classAttribute->newInstance();
            $propertyNameToExposeInsteadOfClass = $attributeInstance->propertyNameToExpose;
        }

        foreach ($this->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $propertyName = $property->getName();
            if ($propertyNameToExposeInsteadOfClass && $propertyName != $propertyNameToExposeInsteadOfClass) {
                continue;
            }
            $visiblePropertyName = $propertyName;
            //attribute OverwritePropertyName changes the visible name of an atribute on serialization
            if ($overwritePropertyNameAttributeInstance = $property->getAttributeInstance(
                OverwritePropertyName::class,
                ReflectionAttribute::IS_INSTANCEOF
            )) {
                /** @var OverwritePropertyName $overwritePropertyNameAttributeInstance */
                $visiblePropertyName = $overwritePropertyNameAttributeInstance->name;
            }
            if (
                (!$ignoreNullValues && $property->isInitialized($this) && $property->isPublic() && !$property->isStatic(
                    )) || ($ignoreNullValues && isset($this->$propertyName))
            ) { // we process only properties that are initialized
                if (
                    !$ignoreHideAttributes && $property->getAttributes(
                        HideProperty::class,
                        ReflectionAttribute::IS_INSTANCEOF
                    ) || !$this->isPropertyVisible($propertyName)
                ) {
                    continue;
                }
                if ($forPersistence && $property->getAttributes(
                        DontPersistProperty::class,
                        ReflectionAttribute::IS_INSTANCEOF
                    )) {
                    continue;
                }
                $propertyValue = $this->$propertyName;
                $serializedValue = $this->serializeProperty(
                    $propertyValue,
                    $cached,
                    $returnUniqueKeyInsteadOfContent,
                    $path,
                    $ignoreHideAttributes,
                    $ignoreNullValues,
                    $forPersistence,
                    $flags
                );

                if (
                    is_array($propertyValue)
                    && is_array($serializedValue)
                    && (
                        $property->hasAttribute(SerializeInToonFormat::class, ReflectionAttribute::IS_INSTANCEOF)
                        || $this->isPropertySerializedAsToon($propertyName)
                    )
                ) {
                    $serializedValue = $this->convertArrayOfObjectsToToon($serializedValue);
                    $visiblePropertyName = SerializeInToonFormat::getToonPropertyName($propertyName);
                }

                if ($serializedValue !== '*RECURSION*') // serialization created recursion loop, we skip the property
                {
                    $resultArray[$visiblePropertyName] = $serializedValue;

                    // Aliases attribute: copy the serialized value to additional alias property names for backward compatibility
                    if ($aliasesAttributeInstance = $property->getAttributeInstance(
                        Aliases::class,
                        ReflectionAttribute::IS_INSTANCEOF
                    )) {
                        /** @var Aliases $aliasesAttributeInstance */
                        foreach ($aliasesAttributeInstance->aliases as $alias) {
                            $resultArray[$alias] = $serializedValue;
                        }
                    }
                }
            }
            if ($propertyNameToExposeInsteadOfClass) {
                // in case we are here, we just ran through the property that is to be exposed, since otherwise we would have continued;
                $resultArray = $resultArray[$visiblePropertyName];
                break;
            }
        }
        if ($cached) {
            SerializerRegistry::setToObjectCacheForObjectId($cacheKey, $resultArray);
            /*
            if ($entityId) {
                SerializerRegistry::setToObjectCacheForObjectId($entityId, $resultArray);
            }*/
        }
        if (empty($resultArray)) {
            return new stdClass(); // Leeres Objekt statt leeres Array zurückgeben
        }

        return $resultArray;
    }

    /**
     * Hook method called before serialization. Implement this method if object requires modifications,
     * such as media item body needs to be nulled due to non utf8 chars
     *
     * @param bool $cached
     * @param bool $returnUniqueKeyInsteadOfContent
     * @param array $path
     * @param bool $ignoreHideAttributes
     * @param bool $ignoreNullValues
     * @param bool $forPersistence
     * @param int $flags Bitwise flags from Serializer class
     * @return void
     */
    public function onToObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true,
        int $flags = 0
    ): void
    {
    }

    /**
     * Recursively serializes property
     *
     * @param mixed $propertyValue
     * @param bool $cached
     * @param bool $returnUniqueKeyInsteadOfContent
     * @param array $path
     * @param bool $ignoreHideAttributes
     * @param bool $ignoreNullValues
     * @param bool $forPersistence
     * @param int $flags Bitwise flags from Serializer class
     * @return mixed
     */
    protected function serializeProperty(
        mixed &$propertyValue,
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true,
        int $flags = 0
    ): mixed
    {
        $propertyValueIsArray = is_array($propertyValue);
        $propertyValueIsObject = is_object($propertyValue);
        if (!$propertyValueIsArray && !$propertyValueIsObject) {
            //simple type e.g. string
            return $propertyValue;
        } elseif ($propertyValueIsObject && method_exists($propertyValue, 'jsonSerialize')) {
            if (method_exists($propertyValue, 'toObject')) {
                // we are in an object that uses the Serializer Trait
                if ($returnUniqueKeyInsteadOfContent && method_exists($propertyValue, 'uniqueKey')) {
                    return $propertyValue->uniqueKey();
                } elseif (isset($path[spl_object_id($propertyValue)])) {
                    // we had the object by its object hash already in the path, so we are in a recursion
                    return '*RECURSION*';
                } elseif (
                    DefaultObject::isEntity(
                        $propertyValue
                    ) && isset($propertyValue->id) && isset($path[$propertyValue::class . '_' . $propertyValue->id])
                ) {
                    // we had the object (found by entity id) already in the path, so we are in a recursion
                    return '*RECURSION*';
                } else {
                    return $propertyValue->toObject(
                        $cached,
                        $returnUniqueKeyInsteadOfContent,
                        $path,
                        $ignoreHideAttributes,
                        $ignoreNullValues,
                        $forPersistence,
                        $flags
                    );
                }
            } else {  // we are in a general object that supports JSON Serialization
                return $propertyValue->jsonSerialize();
            }
        } elseif (
            $propertyValueIsArray || ($propertyValueIsObject && !method_exists($propertyValue, 'jsonSerialize'))
        ) {
            // Handle Enums
            if ($propertyValueIsObject && enum_exists($propertyValue::class)) {
                return $propertyValue->value ?? $propertyValue->name;
            }

            // we have an unkown object which has a toString function, return string number
            if ($propertyValueIsObject && method_exists($propertyValue, '__toString')) {
                return (string)$propertyValue;
            }
            $returnArray = [];
            $elementCount = 0;
            foreach ($propertyValue as $tKey => $tValue) {
                $elementCount++;
                $serializedArrayValue = $this->serializeProperty(
                    $tValue,
                    $cached,
                    $returnUniqueKeyInsteadOfContent,
                    $path,
                    $ignoreHideAttributes,
                    $ignoreNullValues,
                    $forPersistence,
                    $flags
                );
                // we skip empty values and values that created a recursion loop
                if ($serializedArrayValue !== null && $serializedArrayValue !== '*RECURSION*') {
                    $returnArray[$tKey] = $serializedArrayValue;
                }
            }
            // avoid delivering empty array for empty objects in serialization, this leads to issues e.g. with OA schema on empty properties
            if (!$elementCount && $propertyValueIsObject) {
                return (object)$returnArray;
            }
            return $returnArray;
        }
        return null;
    }

    public function jsonSerialize(bool $ignoreHideAttributes = false)
    {
        // unset toObject Cache
        SerializerRegistry::$toOjectCache = [];
        return $this->toObject(ignoreHideAttributes: $ignoreHideAttributes);
    }

    /**
     * Returns false if propertyName is hidden based on class properties and static properties
     * @param string $propertyName
     * @return bool
     */
    public function isPropertyVisible(string $propertyName): bool
    {
        if (isset($this->propertiesToHide[$propertyName])) {
            return false;
        }
        // handle current class
        if (isset(StaticRegistry::$propertiesToHideOnSerialization[static::class][$propertyName])) {
            return false;
        }
        // handle all inheritants of base class using trait
        if (isset(StaticRegistry::$propertiesToHideOnSerialization[self::class][$propertyName])) {
            return false;
        }
        return true;
    }

    /**
     * Convert an array of (already serialized) objects to a compact TOON-like tabular representation.
     *
     * Column selection:
     *  - If a column spec is configured for this class/instance via setToonColumnsSpec()/
     *    setStaticToonColumnsSpec(), only those columns are emitted, in the configured order,
     *    using the alias as column name and the path as lookup into each flattened row.
     *  - Otherwise: columns are the union of all (flattened) property paths across all rows
     *    (the default), and the path serves as both column name and lookup.
     *
     * Missing values are emitted as null in either case.
     *
     * @param array<int|string, mixed> $arrayOfObjects
     */
    private function convertArrayOfObjectsToToon(array $arrayOfObjects): string
    {
        $rows = [];
        foreach ($arrayOfObjects as $item) {
            $rows[] = $this->flattenToonColumns($item);
        }

        $columnsSpec = $this->getToonColumnsSpec();
        if ($columnsSpec !== null) {
            // Explicit spec: columns and order come from the alias map; lookups go via the
            // mapped dot-path into each flattened row.
            $aliasToPath = $columnsSpec;
        } else {
            // Default: union of all flattened keys, in first-seen order. Path == alias.
            $aliasToPath = [];
            foreach ($rows as $row) {
                foreach ($row as $columnName => $_) {
                    if (!isset($aliasToPath[$columnName])) {
                        $aliasToPath[$columnName] = $columnName;
                    }
                }
            }
        }

        $aliases = array_keys($aliasToPath);

        $header = '[' . count($arrayOfObjects) . ']';
        if (!empty($aliases)) {
            $header .= '(' . implode(',', $aliases) . ')';
        }
        $header .= ':';

        $lines = [$header];
        foreach ($rows as $row) {
            $values = [];
            foreach ($aliasToPath as $alias => $path) {
                $values[] = $this->toonEncodeValue($row[$path] ?? null);
            }
            $lines[] = implode(',', $values);
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed> map of flattened column name => scalar (or json-stringified complex) value
     */
    private function flattenToonColumns(mixed $value, string $prefix = ''): array
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        } elseif (is_object($value)) {
            // If it is still an object at this point, serialize to JSON and treat as scalar cell.
            return [
                ($prefix !== '' ? $prefix : 'value') => json_encode(
                    $value,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                )
            ];
        }

        if (is_array($value)) {
            $result = [];

            $keys = array_keys($value);
            $isList = ($keys === [] || $keys === range(0, count($keys) - 1));

            foreach ($value as $k => $v) {
                if ($isList) {
                    $childPrefix = $prefix . '[' . (string)$k . ']';
                } else {
                    $childPrefix = $prefix !== '' ? ($prefix . '.' . (string)$k) : (string)$k;
                }

                $result += $this->flattenToonColumns($v, $childPrefix);
            }

            return $result;
        }

        // scalar
        return [($prefix !== '' ? $prefix : 'value') => $value];
    }

    protected function toonEncodeValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string)$value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return 'null';
            }

            // Avoid exponent notation, normalize -0 to 0
            if ($value == 0.0) {
                return '0';
            }

            $s = rtrim(rtrim(sprintf('%.15F', $value), '0'), '.');
            if ($s === '-0') {
                return '0';
            }

            return $s;
        }

        // Complex values should normally be flattened already; keep a fallback
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $s = (string)$value;

        // Keep rows single-line (avoid multi-line cells, which explode the TOON table and then JSON escaping)
        $s = str_replace(["\r\n", "\r", "\n"], ' ', $s);

        // Minimize escaping because the TOON table is embedded into JSON later anyway.
        // Only quote if needed for comma-separated rows, and only escape quotes.
        $needsQuotes = ($s === '' || str_contains($s, ',') || str_contains($s, '"'));
        if (!$needsQuotes) {
            return $s;
        }

        $s = str_replace('"', '\\"', $s);

        return '"' . $s . '"';
    }

    /**
     * return if this OrmEntity is marked with $index
     * @param $index
     * @return bool
     */
    public function isMarked(string $index): bool
    {
        return isset(SerializerRegistry::$marks[$index]) && isset(
                SerializerRegistry::$marks[$index][spl_object_id(
                    $this
                )]
            );
    }

    /**
     * marks Object with index
     * @param $index
     * @return bool|void
     */
    public function mark(string $index): void
    {
        if (!isset(SerializerRegistry::$marks[$index])) {
            SerializerRegistry::$marks[$index] = [];
        }
        SerializerRegistry::$marks[$index][spl_object_id($this)] = $this;
    }

    /**
     * returns if an index for marks already exists
     * it can be used to identify if a recursive function is called on the highest level,
     * e.g. if the function sets and unsets the index at the beginning and end of execution
     * e.g.
     * function recursive(){
     *   if ($this->markIndexExists('myMark')){
     *      $recursion = true
     *   }
     *   $this->mark('myMark');
     *   ...
     *
     *   if (!$recursion){
     *      clearAllMarksForIndex('myMark');
     *   }
     * }
     * @param string $index
     * @return bool
     */
    public function markIndexExists(string $index): bool
    {
        return isset(SerializerRegistry::$marks[$index]);
    }

    /**
     * removes marks for index
     * @param $index
     * @param false $recursive
     */
    public function unmark($index): void
    {
        if (!isset(SerializerRegistry::$marks[$index])) {
            return;
        }
        if (!isset(SerializerRegistry::$marks[$index][spl_object_id($this)])) {
            return;
        }
        unset(SerializerRegistry::$marks[$index][spl_object_id($this)]);
    }

    public function __toString(): string
    {
        try {
            $json = $this->toJSON();
            if ($json === false) {
                $this->logSerializationIssue('json_encode returned false');
                return '{"error":"json_encode failed"}';
            }
            return $json;
        } catch (Throwable $e) {
            $this->logSerializationIssue('Exception in __toString()', $e);
            return sprintf('{"error":"__toString failed: %s"}', $e->getMessage());
        }
    }

    public function toJSON(bool $ignoreHideAttributes = false)
    {
        return json_encode(
            $this->jsonSerialize($ignoreHideAttributes),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    protected function logSerializationIssue(string $message, ?Throwable $t = null): void
    {
        try {
            $logger = DDDService::instance()->getLogger();

            //dump raw properties
            $rawState = [];
            try {
                $rawState = get_object_vars($this);
            } catch (Throwable $ignored) {
                $rawState = ['error' => 'get_object_vars failed'];
            }

            if ($t) {
                \DDD\Infrastructure\Exceptions\Exception::logShortException(
                    $logger,
                    $message . ' in ' . static::class,
                    $t,
                    5
                );
                $logger->error('Object state (raw) for failed serialization', [
                    'class' => static::class,
                    'rawState' => $rawState,
                ]);
            } else {
                $logger->error(
                    'Serialization issue in ' . static::class . ': ' . $message,
                    [
                        'class' => static::class,
                        'rawState' => $rawState,
                    ]
                );
            }
        } catch (Throwable $inner) {
            // __toString must never explode
            error_log('Logger unavailable in __toString: ' . $inner->getMessage());
        }
    }

    /**
     * we need to avoid to serialize values, that are not ment to be serialized, e.g. partent, children
     * @return array
     */
    public function __serialize(): array
    {
        $return = ['unset' => [], 'properties' => []];
        foreach ($this->getProperties(null, true) as $property) {
            $propertyName = $property->getName();
            $isStatic = $property->isStatic();
            // if property is not set, serializer will wake up the classs with the property default, this creates problems with autoloading
            // as __get is not called anymore
            if (!$isStatic && !isset($this->$propertyName) && isset($this->unsetProperties[$propertyName])) {
                $return['unset'][$propertyName] = true;
                continue;
            }
            if ($property->getAttributes(
                HidePropertyOnSystemSerialization::class,
                ReflectionAttribute::IS_INSTANCEOF
            )) {
                //Lazyload Attributes need to be unset if we want to hide them so lazyloading will work properly with __get
                if ($property->getAttributes(LazyLoad::class, ReflectionAttribute::IS_INSTANCEOF)) {
                    $return['unset'][$propertyName] = true;
                }
                continue;
            }
            // without the following line we have issues to access private properties even in $this context, I think a PHP bug
            $property->setAccessible(true);
            if ($property->isInitialized($this)) {
                $return['properties'][$propertyName] = $property->getValue($this);
            }
        }
        return $return;
    }

    public function __unserialize($data): void
    {
        $unserialized = $data;//unserialize($data);
        foreach ($unserialized['properties'] as $key => $value) {
            $reflectionProperty = new \ReflectionProperty($this, $key);
            // without the following line we have issues to access private properties even in $this context, I think a PHP bug
            $reflectionProperty->setAccessible(true);
            $reflectionProperty->setValue($this, $value);
            $reflectionProperty->setAccessible(false);
        }
        foreach ($unserialized['unset'] as $key => $true) {
            $this->unset($key);
        }
    }

    public function unset(string $propertyName)
    {
        unset($this->$propertyName);
        $this->unsetProperties[$propertyName] = true;
    }

    /**
     * completely fills this object with the property content of another object of the same type which was serialized before
     * @param mixed $otherObject
     * @return void
     * @throws ReflectionException
     */
    public function setPropertiesFromSerializedObject(mixed &$otherObject)
    {
        foreach ($this->getProperties() as $property) {
            $valueFromOther = $otherObject->getDataForProperty($property->getName());
            $property->setAccessible(true);
            $property->setValue($this, $valueFromOther);
        }
    }

    /**
     * gets data from property indiferent if it is private or static etc. by use of reflection
     * @param string $propertyName
     * @return mixed|null
     */
    public function getDataForProperty(string $propertyName)
    {
        $property = $this->getProperty($propertyName);
        $property->setAccessible(true);
        if ($property->isInitialized($this)) {
            return $property->getValue($this);
        }
        return null;
    }

    /**
     * Recursively fills current isntance with data from a default object.
     * It recursively constructs an object structure below the current instance based on a default object
     * It uses reflection in order to determine the allowed type/types of each property tries to apply the data from the default object.
     * Is also able to fill array with instances of the allowed array types.
     * If $throwErrors is true, it throws BadRequest if the data is not matching class definitions and InternalError if problems with
     * the class definitions are found.
     * In case of multiple allowed types (union types), the default object needs to provide by convention an objectType with a fully qualified
     * class name of the object to be instanced, that is one of the allowed types.
     *
     * It uses a static cache for Entities, by Class name and id, and clears this cache after execution of root call, for this purpose
     * also a rootCall parameter is required.
     * @param object $object
     * @param $throwErrors
     * @param bool $rootCall
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function setPropertiesFromObject(
        object &$object,
        $throwErrors = true,
        bool $rootCall = true,
        bool $sanitizeInput = false
    ): void
    {
        $reflectionClass = $this->getReflectionClass();
        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isReadOnly()) {
                continue;
            }
            $propertyName = $property->getName();
            // We ignore elements property in ObjectSet as elements are handled by add() function there in their own setPropertiesFromObject function
            if ($this instanceof ObjectSet && $propertyName == 'elements') {
                continue;
            }
            $setProperty = false;
            // we write the property if it is set, means it has a value or it is null
            // in case of null, we take care to check if the target value supports null
            if (ReflectionClass::isPropertyInitialized($object, $propertyName)) {
                if ($object->$propertyName === null) {
                    $setProperty = $property->allowsNull();
                } else {
                    $setProperty = true;
                }
            }
            if ($setProperty) {
                $this->setPropertyFromObject(
                    $property,
                    $object->$propertyName,
                    $throwErrors,
                    $reflectionClass,
                    $sanitizeInput
                );
            }
        }
        if (DefaultObject::isEntity($this) && isset($this->id) && $this->id) {
            SerializerRegistry::setInstanceForSetPropertiesFromObjectCache($this);
        }
        if ($rootCall) {
            SerializerRegistry::clearSetPropertiesFromObjectCache();
        }
    }

    /**
     * @param \ReflectionProperty|ReflectionProperty $property
     * @param mixed $value
     * @param $throwErrors
     * @param ReflectionClass|null $reflectionClass
     * @param bool $rootCall
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function setPropertyFromObject(
        \ReflectionProperty|ReflectionProperty &$property,
        mixed &$value,
        $throwErrors = true,
        ?ReflectionClass $reflectionClass = null,
        bool $sanitizeInput = false
    ): void
    {
        $propertyName = $property->getName();
        if ($reflectionClass) {
            $reflectionClass = $this->getReflectionClass();
        }
        $allowedTypes = $reflectionClass->getAllowedTypesForProperty($property);

        // type is array
        if ($allowedTypes->isArrayType) {
            // Handle null values for array types when null is allowed
            if ($value === null && $allowedTypes->allowsNull) {
                $this->$propertyName = null;
                return;
            }

            // we have by design an array but $value is not an array
            if (!is_array($value)) {
                if (is_object($value)) {
                    $value = Arr::fromObject($value);
                } else {
                    if ($throwErrors) {
                        throw new BadRequestException(
                            'Property ' . static::class . '->' . $propertyName . ' has to be array'
                        );
                    }
                    return;
                }
            }
            // the types allowed for current array items

            $this->$propertyName = [];
            // we iterate through the array elements
            foreach ($value as $index => $arrayItem) {
                $valueType = gettype($arrayItem);
                //get_type returns e.g. integer, we map this to int
                $valueTypeAllocated = ReflectionClass::GET_TYPE_ALLOCATIONS[$valueType] ?? 'object';
                $valueIsScalar = $valueTypeAllocated != 'object' && $valueTypeAllocated != 'array';

                // if array element is of type array, we let it be and just pass the data
                if (isset($allowedTypes->allowedTypes[ReflectionClass::ARRAY])) {
                    if (is_int($index)) {
                        $this->$propertyName[] = $arrayItem;
                    } else {
                        $this->$propertyName[$index] = $arrayItem;
                    }
                    continue;
                }

                // exact types are found, should happen only on scalar types
                if (isset($allowedTypes->allowedTypes[$valueTypeAllocated])) {
                    if ($sanitizeInput) {
                        $arrayItem = Datafilter::sanitizeInput($arrayItem);
                    }
                    // Handle Enums
                    if ($allowedTypes->isEnum) {
                        if (!isset($allowedTypes->allowedValues[$arrayItem])) {
                            if ($throwErrors) {
                                throw new BadRequestException(
                                    'Property ' . static::class . '->' . $propertyName . ' is an Enum type (' . $allowedTypes->enumType . ') and provided value "' . $arrayItem . '" at index ' . $index . ' is not one of the allowed values (' . implode(
                                        ', ',
                                        array_keys($allowedTypes->allowedValues)
                                    ) . ')'
                                );
                            }
                        } else {
                            $enumCase = $allowedTypes->allowedValues[$arrayItem];
                            $this->$propertyName[] = $enumCase;
                        }
                    } else {
                        $this->$propertyName[] = $arrayItem;
                    }
                    continue;
                }
                if (!$allowedTypes->allowsObject && $valueIsScalar) {
                    if (
                        (isset($allowedTypes->allowedTypes[ReflectionClass::INTEGER]) || isset($allowedTypes->allowedTypes[ReflectionClass::FLOAT])) && is_numeric(
                            $arrayItem
                        )
                    ) {
                        if (isset($allowedTypes->allowedTypes[ReflectionClass::FLOAT])) {
                            $this->$propertyName[] = (float)$arrayItem;
                            continue;
                        }
                        if (isset($allowedTypes->allowedTypes[ReflectionClass::INTEGER])) {
                            $this->$propertyName[] = (int)$arrayItem;
                            continue;
                        }
                    }
                    if (isset($allowedTypes->allowedTypes[ReflectionClass::STRING])) {
                        // Cast any scalar type (string, int, float, bool) to string
                        if (is_scalar($arrayItem)) {
                            if ($sanitizeInput && is_string($arrayItem)) {
                                $arrayItem = Datafilter::sanitizeInput($arrayItem);
                            }
                            // Convert boolean to string representation
                            if (is_bool($arrayItem)) {
                                $arrayItem = $arrayItem ? 'true' : 'false';
                            }
                            $this->$propertyName[] = (string)$arrayItem;
                            continue;
                        }
                    }
                    if (
                        isset($allowedTypes->allowedTypes[ReflectionClass::BOOL]) && (is_bool(
                                $arrayItem
                            ) || $arrayItem === 'true' || $arrayItem == 1.0 || $arrayItem == 1)
                    ) {
                        $this->$propertyName[] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        continue;
                    }
                }
                if (!$allowedTypes->allowsObject && !$valueIsScalar) {
                    throw new BadRequestException(
                        'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . implode(
                            '|',
                            array_keys($allowedTypes->allowedTypes)
                        ) . ', but ' . $valueTypeAllocated . ' provided on index ' . $index
                    );
                }
                if (!$allowedTypes->allowsScalar && $valueIsScalar) {
                    throw new BadRequestException(
                        'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . implode(
                            '|',
                            array_keys($allowedTypes->allowedTypes)
                        ) . ', but ' . $valueTypeAllocated . ' provided on index ' . $index
                    );
                }

                $typeToInstance = null;
                if ($allowedTypes->allowedTypesCount > 1) {
                    // by convention we need an objectType in case of multiple types possible
                    if (
                        isset($value->objectType) && isset(
                            ReflectionClass::getObjectTypeMigrations()[$value->objectType]
                        )
                    ) {
                        $value->objectType = ReflectionClass::getObjectTypeMigrations()[$value->objectType];
                    }
                    if (isset($value->objectType) && isset($allowedTypes->allowedTypes[$value->objectType])) {
                        $typeToInstance = $value->objectType;
                    } else {
                        $validType = false;
                        foreach ($allowedTypes->allowedTypes as $allowedType => $true) {
                            if (isset($value->objectType) && is_a($value->objectType, $allowedType, true)) {
                                $typeToInstance = $value->objectType;
                                $validType = true;
                                break;
                            }
                        }
                        if (!$validType) {
                            if ($throwErrors) {
                                if (isset($value->objectType)) {
                                    throw new BadRequestException(
                                        'Property ' . static::class . '->elements needs to be of type ' . implode(
                                            '|',
                                            array_keys($allowedTypes->allowedTypes)
                                        ) . ', the provided objectType ' . $value->objectType . ' is not supported on index ' . $index
                                    );
                                } else {
                                    throw new BadRequestException(
                                        'Property ' . static::class . '->elements needs to be of type ' . implode(
                                            '|',
                                            array_keys($allowedTypes->allowedTypes)
                                        ) . ', an objectType is required in order to identify the type, but not provided on index ' . $index
                                    );
                                }
                            }
                            return;
                        }
                    }
                } else {
                    // we allow a single type
                    $typeToInstance = array_key_first($allowedTypes->allowedTypes);
                    // we allow subclasses as well if the objectType is of a subclass
                    if (isset($value->objectType)) {
                        if (isset(ReflectionClass::getObjectTypeMigrations()[$value->objectType])) {
                            $value->objectType = ReflectionClass::getObjectTypeMigrations()[$value->objectType];
                        }
                        if (is_a($value->objectType, $typeToInstance, true)) {
                            $typeToInstance = $value->objectType;
                        } else {
                            // objectType does not correspond with type to instance, skip this object
                            $typeToInstance = null;
                        }
                    }
                    // scalar type was not correctly allocated
                    if ($allowedTypes->allowsScalar) {
                        throw new BadRequestException(
                            'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . $typeToInstance . ' but value on index ' . $index . ' was identified as ' . $valueType
                        );
                    }
                }
                // we first try to check if the type to instance is an entity and if we already have set it's properties
                // in this case we can use the SerializerRegistry
                $entityFromCache = false;
                if (
                    DefaultObject::isEntity(
                        $typeToInstance
                    ) && isset($arrayItem->id) && $arrayItem->id && $cachedEntityInstance = SerializerRegistry::getInstanceForSetPropertiesFromObjectCache(
                        $typeToInstance,
                        $arrayItem->id
                    )
                ) {
                    $item = $cachedEntityInstance;
                    $entityFromCache = true;
                } elseif (!$typeToInstance) {
                    $item = null;
                } else {
                    $item = new $typeToInstance();
                }

                if ($item) {
                    if (!$entityFromCache && method_exists($item, 'setPropertiesFromObject')) {
                        $item->setPropertiesFromObject($arrayItem, $throwErrors, false, $sanitizeInput);
                    }
                    $this->$propertyName[] = $item;
                    $this->addChildren($item);
                    if (method_exists($item, 'setParent')) {
                        $item->setParent($this);
                    }
                }
            }
        } else {
            // handle null
            if ($value === null && $allowedTypes->allowsNull) {
                $this->$propertyName = null;
                return;
            } elseif ($value === null && !$allowedTypes->allowsNull) {
                if ($throwErrors) {
                    throw new BadRequestException(
                        'Property ' . static::class . '->' . $propertyName . ' does not allow null'
                    );
                }
                return;
            }
            $valueType = gettype($value);
            //get_type returns e.g. integer, we map this to int
            $valueTypeAllocated = ReflectionClass::GET_TYPE_ALLOCATIONS[$valueType] ?? 'object';
            $valueIsScalar = $valueTypeAllocated != 'object' && $valueTypeAllocated != 'array';

            // exact types are found, should happen only on scalar types
            if (isset($allowedTypes->allowedTypes[$valueTypeAllocated])) {
                if ($sanitizeInput) {
                    $value = Datafilter::sanitizeInput($value);
                }
                // Handle Enums
                if ($allowedTypes->isEnum) {
                    if (!isset($allowedTypes->allowedValues[$value])) {
                        if ($throwErrors) {
                            throw new BadRequestException(
                                'Property ' . static::class . '->' . $propertyName . ' is an Enum type (' . $allowedTypes->enumType . ') and provided value "' . $value . '" is not one of the allowed values (' . implode(
                                    ', ',
                                    array_keys($allowedTypes->allowedValues)
                                ) . ')'
                            );
                        }
                    } else {
                        $enumCase = $allowedTypes->allowedValues[$value];
                        $this->$propertyName = $enumCase;
                    }
                } else {
                    $this->$propertyName = $value;
                }
                return;
            }
            if (!$allowedTypes->allowsObject && $valueIsScalar) {
                if (
                    (isset($allowedTypes->allowedTypes[ReflectionClass::INTEGER]) || isset($allowedTypes->allowedTypes[ReflectionClass::FLOAT])) && is_numeric(
                        $value
                    )
                ) {
                    if (isset($allowedTypes->allowedTypes[ReflectionClass::FLOAT])) {
                        $this->$propertyName = (float)$value;
                        return;
                    }
                    if (isset($allowedTypes->allowedTypes[ReflectionClass::INTEGER])) {
                        $this->$propertyName = (int)$value;
                        return;
                    }
                }
                if (isset($allowedTypes->allowedTypes[ReflectionClass::STRING])) {
                    // Cast any scalar type (string, int, float, bool) to string
                    if (is_scalar($value)) {
                        if ($sanitizeInput && is_string($value)) {
                            $value = Datafilter::sanitizeInput($value);
                        }
                        // Convert boolean to string representation
                        if (is_bool($value)) {
                            $value = $value ? 'true' : 'false';
                        }
                        $this->$propertyName = (string)$value;
                        return;
                    }
                }
                if (
                    isset($allowedTypes->allowedTypes[ReflectionClass::BOOL]) && (is_bool(
                            $value
                        ) || $value === 'true' || $value === '' || $value == 1.0 || $value == 1 || $value === 'false' || $value == 0.0 || $value == 0)
                ) {
                    $this->$propertyName = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    return;
                }
            }
            if (!$allowedTypes->allowsObject && !$valueIsScalar) {
                if ($throwErrors) {
                    throw new BadRequestException(
                        'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . implode(
                            '|',
                            array_keys($allowedTypes->allowedTypes)
                        ) . ', but ' . $valueTypeAllocated . ' provided'
                    );
                }
                return;
            }
            if (!$allowedTypes->allowsScalar && $valueIsScalar) {
                // special handling for Classes supporting fromString function, e.g. DateTime
                if (count($allowedTypes->allowedTypes) == 1) {
                    $typeToInstance = array_key_first($allowedTypes->allowedTypes);
                    if (method_exists($typeToInstance, 'fromString')) {
                        if (!$throwErrors) {
                            try {
                                $loadedInstance = $typeToInstance::fromString($value);
                                if ($loadedInstance) {
                                    $this->$propertyName = $loadedInstance;
                                }
                                return;
                            } catch (Exception) {
                            }
                        } else {
                            $loadedInstance = $typeToInstance::fromString($value);
                            if ($loadedInstance) {
                                $this->$propertyName = $loadedInstance;
                            }
                            return;
                        }
                    }
                }
                if ($throwErrors) {
                    throw new BadRequestException(
                        'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . implode(
                            '|',
                            array_keys($allowedTypes->allowedTypes)
                        ) . ', but ' . $valueTypeAllocated . ' provided'
                    );
                }
                return;
            }

            $typeToInstance = null;
            if ($allowedTypes->allowedTypesCount > 1) {
                // by convention we need an objectType property in case of multiple types possible
                if (
                    isset($value->objectType) && isset(
                        ReflectionClass::getObjectTypeMigrations()[$value->objectType]
                    )
                ) {
                    $value->objectType = ReflectionClass::getObjectTypeMigrations()[$value->objectType];
                }
                if (isset($value->objectType) && isset($allowedTypes->allowedTypes[$value->objectType])) {
                    $typeToInstance = $value->objectType;
                } else {
                    $validType = false;
                    foreach ($allowedTypes->allowedTypes as $allowedType => $true) {
                        $invalidClass = false;
                        try {
                            if (isset($value->objectType) && !class_exists($value->objectType)) {
                                $invalidClass = true;
                            }
                        } catch (Exception) {
                            $invalidClass = true;
                        }

                        if (
                            !$invalidClass && isset($value->objectType) && is_a(
                                $value->objectType,
                                $allowedType,
                                true
                            )
                        ) {
                            $typeToInstance = $value->objectType;
                            $validType = true;
                            break;
                        }
                    }
                    if (!$validType) {
                        if ($throwErrors) {
                            if (isset($value->objectType)) {
                                throw new BadRequestException(
                                    'Property ' . static::class . '->elements needs to be of type ' . implode(
                                        '|',
                                        array_keys($allowedTypes->allowedTypes)
                                    ) . ', the provided objectType "' . $value->objectType . '" is not supported'
                                );
                            } else {
                                throw new BadRequestException(
                                    'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . implode(
                                        '|',
                                        array_keys($allowedTypes->allowedTypes)
                                    ) . ', an objectType is required in order to identify the type'
                                );
                            }
                        }
                        return;
                    }
                }
            } else {
                // we allow a single type
                $typeToInstance = array_key_first($allowedTypes->allowedTypes);
                // we allow subclasses as well if the objectType is of a subclass
                if (isset($value->objectType)) {
                    if (isset(ReflectionClass::getObjectTypeMigrations()[$value->objectType])) {
                        $value->objectType = ReflectionClass::getObjectTypeMigrations()[$value->objectType];
                    }
                    if (is_a($value->objectType, $typeToInstance, true)) {
                        $typeToInstance = $value->objectType;
                    } else {
                        // objectType does not correspond with type to instance, skip this object
                        $typeToInstance = null;
                    }
                }
                // scalar type was not correctly allocated
                if ($allowedTypes->allowsScalar) {
                    throw new BadRequestException(
                        'Property ' . static::class . '->' . $propertyName . ' needs to be of type ' . $typeToInstance . ' but value was identified as ' . $valueType
                    );
                }
            }

            try {
                $entityFromCache = false;
                if (
                    DefaultObject::isEntity(
                        $typeToInstance
                    ) && isset($value->id) && $value->id && $cachedEntityInstance = SerializerRegistry::getInstanceForSetPropertiesFromObjectCache(
                        $typeToInstance,
                        $value->id
                    )
                ) {
                    $this->$propertyName = $cachedEntityInstance;
                    $entityFromCache = true;
                } elseif ($typeToInstance) {
                    $this->$propertyName = new $typeToInstance();
                }
            } catch (Error $e) {
                throw new InternalErrorException(
                    'Property ' . static::class . '->' . $propertyName . ' is defined to be of type ' . implode(
                        '|',
                        array_keys($allowedTypes->allowedTypes)
                    ) . ', but class does not exist: ' . $typeToInstance
                );
            }
            if (isset($this->$propertyName) && $this->$propertyName) {
                if (!$entityFromCache && method_exists($this->$propertyName, 'setPropertiesFromObject')) {
                    if (is_array($value)) {
                        // Make it possible to set elements to ObjectSets without passing the values in .elements property
                        // and allow values being passed as array directly
                        if ($this->$propertyName instanceof ObjectSet) {
                            $tObject = new stdClass();
                            $tObject->elements = $value;
                            $value = $tObject;
                        } else {
                            // empty objects come as arrays and have to be converted
                            $value = (object)$value;
                        }
                    }
                    if (!$entityFromCache) {
                        $this->$propertyName->setPropertiesFromObject($value, $throwErrors, false, $sanitizeInput);
                    }
                }
                if (method_exists($this, 'addChildren')) {
                    $this->addChildren($this->$propertyName);
                    if (method_exists($this->$propertyName, 'setParent')) {
                        $this->$propertyName->setParent($this);
                    }
                }
            }
            return;
        }
    }
}