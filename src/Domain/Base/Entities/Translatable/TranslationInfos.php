<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Translatable;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * @property Entity $parent
 * @method Entity getParent()
 */
class TranslationInfos extends ValueObject
{
    /** @var array[] Stores translations applied with setter function */
    public array $translationsStore = [];

    /**
     * The current language code.
     * @var string
     */
    public string $currentLanguageCode;

    /**
     * The current country code of the application.
     * @var string
     */
    public ?string $currentCountryCode;

    /**
     * The current writing style used in the application.
     * @var string
     */
    #[Choice([Translatable::WRITING_STYLE_FORMAL, Translatable::WRITING_STYLE_INFORMAL])]
    public string $currentWritingStyle;

    /**
     * Default language code.
     *
     * This variable stores the default language code that will be used when no language code is provided.
     * @var string
     */
    public string $defaultLanguageCode;

    /**
     * The default writing style used in the application.
     * @var string
     */
    #[Choice([Translatable::WRITING_STYLE_FORMAL, Translatable::WRITING_STYLE_INFORMAL])]
    public string $defaultWritingStyle;

    /**
     * Array that stores the available language codes, locales or locales + writing styles for which translations exist
     * Possible formats are:
     * <languageCode> => true
     * <languageCode>::<wrintingStyle> => true
     * <locale> => true
     * <locale>::<wrintingStyle> => true
     * @var array
     */
    public array $availableTranslations = [];

    /**
     * Indicates whether the entity has a translation for the current language code.
     * @var bool
     */
    public bool $hasTranslationForCurrentLanguageCode = false;

    /**
     * Indicates whether the entity has a translation for the current country code
     * @var bool
     */
    public bool $hasTranslationForCurrentCountryCode = false;

    /**
     * Indicates whether the entity has a translation for the writing style.
     * @var bool
     */
    public bool $hasTranslationForCurrentWritingStyle = false;

    /**
     * Retrieves the translations for a given property.
     * @param string $propertyName The name of the property to retrieve translations for.
     * @param bool $forPersistence If true, we are operating in context of mapping the values for persistance, in this case also the value of the property is taken into consideration
     * @return array|null An array of translations, or null if the property does not exist or has no translations.
     */
    public function getTranslationsForProperty(string $propertyName, bool $forPersistence = false): ?array
    {
        if (!$this->getParent()) {
            return null;
        }
        if (!property_exists($this->getParent(), $propertyName)) {
            return null;
        }
        $translations = [];
        if (!isset($this->getParent()->$propertyName)) {
            if (!isset($this->translationsStore[$propertyName])) {
                return null;
            }
            return $this->translationsStore[$propertyName];
        }
        // We are operating in context of mapping the values for persistance, in this case also the value of the property is taken into consideration
        // Otherwise not, as we want to have the values "as they are" and understand if a translation is missing or not
        if (isset($this->translationsStore[$propertyName])) {
            $translations = array_merge($translations, $this->translationsStore[$propertyName]);
        }
        if (isset($this->getParent()->$propertyName) && $this->getParent()->$propertyName !== null && $forPersistence) {
            $translations[Translatable::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle()] = $this->getParent()->$propertyName;
        }
        return $translations;
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
        if (!$this->getParent()) {
            return;
        }
        if (!property_exists($this->getParent(), $propertyName)) {
            return;
        }
        $this->translationsStore[$propertyName] = $translations;
        $defaultTranslationWithFallback = $this->getTranslationForProperty(
            $propertyName,
            Translatable::getCurrentLanguageCode(),
            Translatable::getCurrentCountryCode(),
            Translatable::getCurrentWritingStyle(),
            useFallBack: true
        );
        if ($defaultTranslationWithFallback) {
            $this->getParent()->$propertyName = $defaultTranslationWithFallback;
        }
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
        if (!$this->getParent()) {
            return;
        }
        if (!property_exists($this->getParent(), $propertyName)) {
            return;
        }
        $key = Translatable::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
            $languageCode,
            $countryCode,
            $writingStyle
        );
        $this->translationsStore[$propertyName][$key] = $translation;

        // if we are in the default state and all parameter matches, we also set the Entity's property to the translation value
        if (
            (!$languageCode || $languageCode == Translatable::getCurrentLanguageCode(
                )) && (!$countryCode || $countryCode == Translatable::getCurrentCountryCode(
                )) && (!$writingStyle || $writingStyle == Translatable::getCurrentWritingStyle())
        ) {
            $this->getParent()->$propertyName = $translation;
        }
    }

    /**
     * Retrieves the translation for a specific property.
     *
     * @param string $propertyName The name of the property to retrieve the translation for.
     * @param string|null $languageCode The language code. Default is null.
     * @param string|null $countryCode The country code. Default is null.
     * @param string|null $writingStyle The writing style. Default is null.
     * @param bool $useFallBack Set to true to use fallback translations. Default is false.
     * @return string|null The translation for the property, or null if no translation is found.
     */
    public function getTranslationForProperty(
        string $propertyName,
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null,
        bool $useFallBack = false
    ): ?string {
        if (!($this->getParent() && property_exists($this->getParent(), $propertyName))) {
            return null;
        }
        $key = Translatable::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
            $languageCode,
            $countryCode,
            $writingStyle
        );
        $translation = $this->translationsStore[$propertyName][$key] ?? null;
        // try to find translation with that is less specific
        if ($translation === null && $useFallBack) {
            // if countryCode was given, we try without country code
            if ($countryCode) {
                $key = Translatable::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
                    $languageCode,
                    '',
                    $writingStyle
                );
                $translation = $this->translationsStore[$propertyName][$key] ?? null;
                if ($translation) {
                    return $translation;
                }
                // if writing style was given, we try without
                if ($writingStyle) {
                    // try out switching writing style
                    $writingStyle = $writingStyle == Translatable::WRITING_STYLE_INFORMAL ? Translatable::WRITING_STYLE_FORMAL : Translatable::WRITING_STYLE_INFORMAL;
                    $key = Translatable::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
                        $languageCode,
                        '',
                        null
                    );
                    $translation = $this->translationsStore[$propertyName][$key] ?? null;
                    if ($translation) {
                        return $translation;
                    }
                }
            }
        }
        // If no translation is found and we have set fallback to default language, returns default language
        if (!$translation && Translatable::fallbackToDefaultLanguageIfNoTranslationIsPresent()) {
            $key = Translatable::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
                Translatable::getDefaultLanguageCode(), '',
            );
            $translation = $this->translationsStore[$propertyName][$key] ?? null;
            if ($translation) {
                return $translation;
            }
        }
        return $translation;
    }
}