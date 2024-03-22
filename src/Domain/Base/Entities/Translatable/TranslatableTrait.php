<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Translatable;

use DDD\Domain\Base\Entities\ParentChildrenTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

trait TranslatableTrait
{
    use ParentChildrenTrait;

    /**
     * @var TranslationInfos|null Holds information about translations
     */
    #[DatabaseColumn(ignoreProperty: true)]
    public ?TranslationInfos $translationInfos;

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
    public function getTranslationsForProperty(string $propertyName): ?array{
        return $this->getTranslationInfos()->getTranslationsForProperty($propertyName);
    }

    /**
     * Sets translations for a specific property.
     *
     * @param string $propertyName The name of the property to set translations for.
     * @param array $translations An associative array where the keys represent language codes and the values represent the translations.
     * @return void
     */
    public function setTranslationsForProperty(string $propertyName, array $translations): void {
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
    public function setTranslationForProperty(string $propertyName, string $translation, string $languageCode = null, string $countryCode = null, string $writingStyle = null):void{
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
    public function getTranslationForProperty(string $propertyName, string $languageCode = null, string $countryCode = null, string $writingStyle = null, bool $useFallBack = false):?string{
        return $this->getTranslationInfos()->getTranslationForProperty($propertyName, $languageCode, $countryCode, $writingStyle);
    }
}