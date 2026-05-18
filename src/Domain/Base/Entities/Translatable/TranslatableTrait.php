<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Translatable;

use DDD\Domain\Base\Entities\ParentChildrenTrait;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use ReflectionException;

trait TranslatableTrait
{
    use ParentChildrenTrait;

    /**
     * @var TranslationInfos|null Holds information about translations
     */
    #[DatabaseColumn(ignoreProperty: true)]
    public ?TranslationInfos $translationInfos;

    /**
     * Per-instance sentinel that captures `spl_object_id($this->translationInfos)` of the last
     * successful materialization run. When the next validate() pass on this instance sees the
     * same translationInfos identity, the materialization loop is skipped — protects against
     * redundant work when validate() is called multiple times within the same update lifecycle
     * (DTO-level → repo-level → recursive child-level).
     */
    protected ?int $lastTranslatableMaterializationFingerprint = null;

    public function getTranslationInfos(): TranslationInfos
    {
        if (isset($this->translationInfos)) {
            return $this->translationInfos;
        }
        $this->translationInfos = new TranslationInfos();
        $this->addChildren($this->translationInfos);
        $this->translationInfos->currentLanguageCode = Translatable::getCurrentLanguageCode();
        $this->translationInfos->currentCountryCode = Translatable::getCurrentCountryCode();
        $this->translationInfos->currentWritingStyle = Translatable::getCurrentWritingStyle();

        $this->translationInfos->defaultLanguageCode = Translatable::getDefaultLanguageCode();
        $this->translationInfos->defaultWritingStyle = Translatable::getDefaultWritingStyle();
        return $this->translationInfos;
    }

    /**
     * Retrieves the translations for a given property.
     * @param string $propertyName The name of the property to retrieve translations for.
     * @return array|null An array of translations, or null if the property does not exist or has no translations.
     */
    public function getTranslationsForProperty(string $propertyName): ?array
    {
        return $this->getTranslationInfos()->getTranslationsForProperty($propertyName);
    }

    /**
     * Sets translations for a specific property.
     *
     * @param string $propertyName The name of the property to set translations for.
     * @param array $translations An associative array where the keys represent language codes and the values represent the translations.
     * @return void
     */
    public function setTranslationsForProperty(string $propertyName, array $translations): void
    {
        $this->getTranslationInfos()->setTranslationsForProperty($propertyName, $translations);
    }

    /**
     * Sets the translation for a specific property.
     *
     * @param string $propertyName The name of the property to set the translation for.
     * @param string $translation The translation to set for the property.
     * @param string|null $languageCode The language code. Default is null.
     * @param string|null $countryCode The country code. Default is null.
     * @param string|null $writingStyle The writing style. Default is null.
     * @return void
     */
    public function setTranslationForProperty(
        string $propertyName,
        string $translation,
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null
    ): void {
        $this->getTranslationInfos()->setTranslationForProperty($propertyName, $translation, $languageCode, $countryCode, $writingStyle);
    }

    /**
     * Retrieves the translation for a specific property.
     *
     * @param string $propertyName The name of the property to retrieve the translation for.
     * @param string|null $languageCode The language code. Default is null.
     * @param string|null $countryCode The country code. Default is null.
     * @param string|null $writingStyle The writing style. Default is null.
     * @param bool $useFallBack Whether to use fallback translations. Default is false.
     * @return string|null The translation for the property, or null if not found.
     */
    public function getTranslationForProperty(
        string $propertyName,
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null,
        bool $useFallBack = false
    ): ?string {
        return $this->getTranslationInfos()->getTranslationForProperty($propertyName, $languageCode, $countryCode, $writingStyle);
    }

    /**
     * Returns all properties with Translatable attribute
     * @return ReflectionProperty[]
     * @throws ReflectionException
     */
    public function getTranslatableProperties(): array
    {
        if (isset(StaticRegistry::$translatableProperties[static::class])) {
            return StaticRegistry::$translatableProperties[static::class];
        }
        $refectionClass = ReflectionClass::instance(static::class);
        $translatableProperties = [];
        foreach ($refectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->hasAttribute(Translatable::class)) {
                $translatableProperties[] = $property;
            }
        }
        StaticRegistry::$translatableProperties[static::class] = $translatableProperties;
        return StaticRegistry::$translatableProperties[static::class];
    }

    /**
     * Materializes the scalar value of every #[Translatable] property from the translations carried
     * in `$this->translationInfos->translationsStore`. Called from {@see \DDD\Infrastructure\Traits\ValidatorTrait::validate()}
     * as a preamble so constraint checks (NotNull, NotBlank, …) see the populated scalar rather
     * than the post-deserialization null.
     *
     * The HTTP-deserialization path through {@see \DDD\Infrastructure\Traits\Serializer\SerializerTrait::setPropertiesFromObject()}
     * fills `translationsStore` via reflection but never calls {@see TranslationInfos::setTranslationsForProperty()},
     * which is the only place that bridges the store to the parent scalar property. The DB→Entity
     * path doesn't have this gap because {@see \DDD\Domain\Base\Repo\DB\DBEntity::mapToEntity()} calls
     * `setTranslationsForProperty()` directly. This method closes the HTTP gap.
     *
     * Selection logic per property:
     *   1. Skip when no translations carried for the property.
     *   2. Skip when the scalar is already non-null (never overwrite an existing value — protects
     *      PATCH flows where `find()` already populated the scalar from the DB column).
     *   3. Try the standard fallback chain: {@see TranslationInfos::getTranslationForProperty()}
     *      with `useFallBack: true` walks (country-strip → alt-writing-style → default-language →
     *      native value).
     *   4. Last-resort: pick the first non-empty entry from the property's translations subarray.
     *      Covers the "operator typed in a locale not present in the request's current-language
     *      configuration" case (e.g. world.supportedWorldLocales doesn't contain the admin's
     *      account languageCode).
     *
     * Performance:
     *   - Skipped entirely when `translationInfos` is unset or `translationsStore` is empty.
     *   - Skipped on repeat invocations for the same `translationInfos` instance via the
     *     per-instance fingerprint sentinel.
     *   - Reflection cost amortised by {@see self::getTranslatableProperties()}'s existing per-class
     *     cache in {@see StaticRegistry::$translatableProperties}.
     *   - Locale state (currentLanguageCode/Country/WritingStyle) read once per call into locals.
     *
     * @throws ReflectionException
     */
    public function materializeTranslatablePropertiesFromInfos(): void
    {
        if (!isset($this->translationInfos) || empty($this->translationInfos->translationsStore)) {
            return;
        }
        $fingerprint = spl_object_id($this->translationInfos);
        if ($this->lastTranslatableMaterializationFingerprint === $fingerprint) {
            return;
        }

        $translatableProperties = $this->getTranslatableProperties();
        if ($translatableProperties === []) {
            $this->lastTranslatableMaterializationFingerprint = $fingerprint;
            return;
        }

        $lang = Translatable::getCurrentLanguageCode();
        $country = Translatable::getCurrentCountryCode();
        $writingStyle = Translatable::getCurrentWritingStyle();
        $store = $this->translationInfos->translationsStore;

        foreach ($translatableProperties as $reflectionProperty) {
            $propertyName = $reflectionProperty->getName();
            $entries = $store[$propertyName] ?? null;
            if (empty($entries)) {
                continue;
            }
            // Never overwrite an already-set scalar. Protects PATCH flows where find() populated
            // the scalar from the DB column, and the request only carries a sparse translations
            // diff whose fallback chain would otherwise resolve to a different (possibly null)
            // value than what's currently persisted.
            if (isset($this->{$propertyName}) && $this->{$propertyName} !== null) {
                continue;
            }
            $value = $this->translationInfos->getTranslationForProperty(
                $propertyName,
                $lang,
                $country,
                $writingStyle,
                useFallBack: true
            );
            // Last-resort: any non-empty entry in the property's translations subarray. Iterates
            // only the relevant subarray (already in hand), not the full store.
            if ($value === null) {
                foreach ($entries as $candidate) {
                    if (is_string($candidate) && $candidate !== '') {
                        $value = $candidate;
                        break;
                    }
                }
            }
            if ($value !== null) {
                $this->{$propertyName} = $value;
            }
        }

        $this->lastTranslatableMaterializationFingerprint = $fingerprint;
    }
}