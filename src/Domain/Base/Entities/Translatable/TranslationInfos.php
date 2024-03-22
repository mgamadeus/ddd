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
    /** @var array Stores translations applied with setter function */
    protected array $translationsStore = [];

    /**
     * The current language code.
     * @var string
     */
    public string $currentLanguageCode;

    /**
     * The current locale of the application.
     * @var string
     */
    public string $currentLocale;

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
     * The default locale used in the application.
     * @var string
     */
    public string $defaultLocale;

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
     * Indicates whether the entity has a translation for the current language code.
     * @var bool
     */
    public bool $hasTranslationForCurrentLocale = false;

    /**
     * Indicates whether the entity has a translation for the writing style.
     * @var bool
     */
    public bool $hasTranslationForCurrentWritingStyle = false;

    public function getTranslationsForProperty(string $propertyName): ?array
    {
        $translations = [];
        if (!isset($this->getParent()->$propertyName)) {
            if (!isset($this->translationsStore[$propertyName])) {
                return null;
            }
            return $this->translationsStore[$propertyName];
        }
        $translations[Translatable::getTranslationKeyForLanguageCodeLocaleAndWritingStyle()] = $this->getParent()->$propertyName;
        if (isset($this->translationsStore[$propertyName])) {
            $translations = array_merge_recursive($translations, $this->translationsStore[$propertyName]);
        }
        return $translations;
    }

    public function getTranslationsForPropertyAsJson(string $propertyName): ?string
    {
        $translationsForProperty = $this->getTranslationsForProperty($propertyName);
        if (!$translationsForProperty) {
            return null;
        }
        return json_encode($translationsForProperty, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}