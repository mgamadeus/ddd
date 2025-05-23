<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Translatable;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Validation\Constraints\Choice;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Translatable extends ValueObject
{
    use BaseAttributeTrait;

    /**
     * The constant representing the formal writing style.
     * This writing style is used for more professional or serious contexts.
     * @var string
     */
    public const WRITING_STYLE_FORMAL = 'FORMAL';

    /** Indicates an informal writing style. */
    public const WRITING_STYLE_INFORMAL = 'INFORMAL';

    /**
     * The default writing style used in the application.
     * By default, it is set to WRITING_STYLE_FORMAL.
     * @var string
     */
    public const DEFAULT_WRITING_STYLE = self::WRITING_STYLE_FORMAL;

    /**
     * The default language code used in the application.
     * @var string DEFAULT_LANGUAGE_CODE
     */
    public const DEFAULT_LANGUAGE_CODE = 'en';

    /**
     * The current language code.
     * @var string
     */
    public static string $currentLanguageCode;

    /**
     * The current country code of the application.
     * @var string
     */
    public static ?string $currentCountryCode;

    /**
     * @var string[] Active language codes
     */
    public static array $activeLanguageCodes;

    /**
     * @var string[] Active locales codes
     */
    public static array $activeLocales;

    /**
     * The current writing style used in the application.
     * @var string
     */
    #[Choice([self::WRITING_STYLE_FORMAL, self::WRITING_STYLE_INFORMAL])]
    public static string $currentWritingStyle;

    /**
     * Default language code.
     *
     * This variable stores the default language code that will be used when no language code is provided.
     * @var string
     */
    public static string $defaultLanguageCode;

    /**
     * The default writing style used in the application.
     * @var string
     */
    #[Choice([self::WRITING_STYLE_FORMAL, self::WRITING_STYLE_INFORMAL])]
    public static string $defaultWritingStyle;

    /**
     * If no translation if found in given language, this setting determines, that default language content is returned
     * @var bool
     */
    public static bool $fallbackToDefaultLanguageCode;

    /**
     * @var array|null Snapshot of translation settings to be easily restored
     */
    protected static ?array $translationSettingsSnapshot = null;

    /**
     * @return void Sets a snapshot of current translation settings to be easily restored when needed, it does not set a snapshot if one already exists
     */
    public static function setTranslationSettingsSnapshot(): void
    {
        if (self::$translationSettingsSnapshot !== null) {
            return;
        }
        self::$translationSettingsSnapshot = [
            'writingStyle' => static::getCurrentWritingStyle(),
            'languageCode' => static::getCurrentLanguageCode(),
            'countryCode' => static::getCurrentCountryCode()
        ];
    }

    /**
     * @return void Restores snapshot of translation settings if present
     */
    public static function restoreTranslationSettingsSnapshot(): void
    {
        if (!self::$translationSettingsSnapshot) {
            return;
        }
        if (isset(self::$translationSettingsSnapshot['writingStyle']) && self::$translationSettingsSnapshot['writingStyle']) {
            static::setCurrentWritingStyle(self::$translationSettingsSnapshot['writingStyle']);
        }
        if (isset(self::$translationSettingsSnapshot['languageCode']) && self::$translationSettingsSnapshot['languageCode']) {
            static::setCurrentLanguageCode(self::$translationSettingsSnapshot['languageCode']);
        }
        if (isset(self::$translationSettingsSnapshot['countryCode']) && self::$translationSettingsSnapshot['countryCode']) {
            static::setCurrentCountryCode(self::$translationSettingsSnapshot['countryCode']);
        }
        self::$translationSettingsSnapshot = null;
    }

    /**
     * Returns true, if the current language code has already been set.
     * Usefull for example if language can be determined either by a request parameter or by the Account's
     * default languageCode, so
     * @return bool
     */
    public static function isCurrentLanguageSet(): bool
    {
        if (isset(self::$currentLanguageCode)) {
            return true;
        }
        return false;
    }

    /**
     * Checks if the system should fallback to the default language if no translation is present.
     *
     * @return bool Returns true if the system should fallback to the default language, false otherwise.
     */
    public static function fallbackToDefaultLanguageIfNoTranslationIsPresent(): bool
    {
        if (isset(self::$fallbackToDefaultLanguageCode)) {
            return self::$fallbackToDefaultLanguageCode;
        }
        $fallbackToDefaultLanguageCode = Config::getEnv('TRANSLATABLE_FALLBACK_TO_DEFAULT_LANGUAGE_CODE');
        if (isset($fallbackToDefaultLanguageCode)) {
            self::$fallbackToDefaultLanguageCode = $fallbackToDefaultLanguageCode;
        } else {
            self::$fallbackToDefaultLanguageCode = false;
        }
        return self::$fallbackToDefaultLanguageCode;
    }

    /**
     * Gets the default language code.
     *
     * If the default language code is set, it will return the value.
     * Otherwise, it will check for the TRANSLATABLE_DEFAULT_LANGUAGE_CODE environment variable
     * and if found, set it as the default language code.
     * If the environment variable is not set, it will use the DEFAULT_LANGUAGE_CODE constant as the default value.
     * @return string The default language code.
     */
    public static function getDefaultLanguageCode(): string
    {
        if (isset(self::$defaultLanguageCode)) {
            return self::$defaultLanguageCode;
        }
        if ($defaultLanguageCode = Config::getEnv('TRANSLATABLE_DEFAULT_LANGUAGE_CODE')) {
            self::$defaultLanguageCode = $defaultLanguageCode;
        } else {
            self::$defaultLanguageCode = static::DEFAULT_LANGUAGE_CODE;
        }
        return self::$defaultLanguageCode;
    }

    /**
     * Returns active language codes
     * @return string[]
     */
    public static function getActiveLanguageCodes(): array
    {
        if (isset(self::$activeLanguageCodes)) {
            return self::$activeLanguageCodes;
        }
        if ($activeLanguageCodes = Config::getEnv('TRANSLATABLE_ACTIVE_LANGUAGE_CODES')) {
            self::$activeLanguageCodes = explode(',', $activeLanguageCodes);
        }
        return self::$activeLanguageCodes;
    }

    /**
     * Returns active locales
     * @return string[]
     */
    public static function getActiveLocales(): array
    {
        if (isset(self::$activeLocales)) {
            return self::$activeLocales;
        }
        if ($activeLocales = Config::getEnv('TRANSLATABLE_ACTIVE_LOCALES')) {
            self::$activeLocales = explode(',', $activeLocales);
        }
        return self::$activeLocales;
    }

    /**
     * Returns true if given Language is contained in activeLanguageCodes
     * @param string $languageCode
     * @return bool
     */
    public static function isSupportedLanguageCode(string $languageCode): bool
    {
        return in_array($languageCode, self::getActiveLanguageCodes());
    }

    /**
     * Retrieves the default writing style.
     * @return string The default writing style.
     */
    public static function getDefaultWritingStyle(): string
    {
        if (isset(self::$defaultWritingStyle)) {
            return self::$defaultWritingStyle;
        }
        if ($defaultWritingStyle = Config::getEnv('TRANSLATABLE_DEFAULT_WRITING_STYLE')) {
            self::$defaultWritingStyle = $defaultWritingStyle;
        } else {
            self::$defaultWritingStyle = self::DEFAULT_WRITING_STYLE;
        }
        return self::$defaultWritingStyle;
    }

    /**
     * Returns the current language code.
     *
     * If the current language code has been previously set, it is returned. Otherwise, the default language code is retrieved
     * and set as the current language code before returning it.
     * @return string The current language code.
     */
    public static function getCurrentLanguageCode(): string
    {
        if (isset(self::$currentLanguageCode)) {
            return self::$currentLanguageCode;
        }
        self::$currentLanguageCode = static::getDefaultLanguageCode();
        return self::$currentLanguageCode;
    }

    /**
     * Retrieves the current writing style.
     *
     * If the current writing style is set, it will be returned.
     * Otherwise, it will be set to the default writing style.
     *
     * @return string The current writing style.
     */
    public static function getCurrentWritingStyle(): string
    {
        if (isset(self::$currentWritingStyle)) {
            return self::$currentWritingStyle;
        }
        self::$currentWritingStyle = static::getDefaultWritingStyle();
        return self::$currentWritingStyle;
    }

    /**
     * Retrieves the current locale.
     * @return string|null The current locale.
     */
    public static function getCurrentCountryCode(): ?string
    {
        if (isset(self::$currentCountryCode)) {
            return self::$currentCountryCode;
        }
        return null;
    }

    /**
     * Sets the current language code.
     * @param string $languageCode The language code to set.
     * @return void
     */
    public static function setCurrentLanguageCode(string $languageCode): void
    {
        self::$currentLanguageCode = $languageCode;
    }

    /**
     * Sets the current writing style.
     * @param string $writingStyle The new writing style to set.
     * @return void
     */
    public static function setCurrentWritingStyle(string $writingStyle): void
    {
        self::$currentWritingStyle = $writingStyle;
    }

    /**
     * Set the current country code.
     * @param string $countryCode The country code to set.
     * @return void
     */
    public static function setCurrentCountryCode(?string $countryCode): void
    {
        self::$currentCountryCode = $countryCode;
    }

    /**
     * Returns the index under wich to store the translation based on languageCode, countryCode and writingStyle, if any of these are not provided, default values are used
     * @param string|null $languageCode
     * @param string|null $countryCode
     * @param string|null $writingStyle
     * @return string
     */
    public static function getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null
    ): string {
        $languageCode = $languageCode ?? static::getCurrentLanguageCode();
        $countryCode = $countryCode ?? (static::getCurrentCountryCode() ?? '');
        $writingStyle = $writingStyle ?? static::getCurrentWritingStyle();
        return $languageCode . ':' . $countryCode . ':' . $writingStyle;
    }

    /**
     * Translates the given translation key based on the provided language code, country code, writing style,
     * and fallback option.
     *
     * @param string $translationKey The translation key to be translated.
     * @param string|null $languageCode The language code to be used for translation. (Optional)
     * @param string|null $countryCode The country code to be used for translation. (Optional)
     * @param string|null $writingStyle The writing style to be used for translation. (Optional)
     * @param bool $useFallBack Indicates whether to use fallback when translation is not found. (Default: false)
     * @param string[] $placeholders Associative array of placeholders to replace in format ['placeholder' => 'value'], they replace %varname% in content
     * @return string The translated value for the given translation key, or the translation key itself if no translation
     *              is found and fallback is not enabled.
     */
    public static function translateKey(
        string $translationKey,
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null,
        bool $useFallBack = false,
        array $placeholders = []
    ): string {
        $translationsFromConfig = Config::get('Common.Translations');
        $index = static::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
            $languageCode,
            $countryCode,
            $writingStyle
        );
        $translation = $translationsFromConfig[$translationKey][$index] ?? null;
        // try to find translation with that is less specific
        if ($translation === null && $useFallBack) {
            // if countryCode was given, we try without country code
            if ($countryCode) {
                $index = static::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
                    $languageCode,
                    '',
                    $writingStyle
                );
                $translation = $translationsFromConfig[$translationKey][$index] ?? null;
                if ($translation) {
                    return static::replacePlaceholders($translation, $placeholders);
                }
                // if writing style was given, we try without
                if ($writingStyle) {
                    // try out switching writing style
                    $writingStyle = $writingStyle == self::WRITING_STYLE_INFORMAL ? self::WRITING_STYLE_FORMAL : self::WRITING_STYLE_INFORMAL;
                    $index = static::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
                        $languageCode,
                        '',
                        null
                    );
                    $translation = $translationsFromConfig[$translationKey][$index] ?? null;
                    if ($translation) {
                        return static::replacePlaceholders($translation, $placeholders);
                    }
                }
            }
        }
        // If not translation is found and we have set fallback to default language, returns default language
        if (!$translation && Translatable::fallbackToDefaultLanguageIfNoTranslationIsPresent()) {
            $index = Translatable::getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
                Translatable::getDefaultLanguageCode()
            );
            $translation = $translationsFromConfig[$translationKey][$index] ?? null;
            if ($translation) {
                return static::replacePlaceholders($translation, $placeholders);
            }
        }
        // if nothing is found, return key itself
        if (!$translation) {
            $translation = $translationKey;
        }
        return static::replacePlaceholders($translation, $placeholders);
    }

    /**
     * Replaces placeholders in the input string with values from an associative array.
     * Placeholders are expected to be in the format %varname%.
     *
     * @param string $input The input string containing placeholders.
     * @param string[] $placeholders Associative array of placeholders to replace in format ['varname' => 'value'].
     * @return string The processed string with placeholders replaced by their corresponding values.
     */
    public static function replacePlaceholders(string $input, array $placeholders): string
    {
        foreach ($placeholders as $placeholder => $value) {
            $input = str_replace("%$placeholder%", (string)$value, $input);
        }
        return $input;
    }

    /**
     * Sets the visibility of the translation information.
     *
     * @param bool $visible Determines whether the translation information should be visible.
     *                      If true, the translation info will be shown; otherwise, it will be hidden.
     * @return void
     */
    public static function setTranslationInfosVisibility(bool $visible): void
    {
        if ($visible) {
            DefaultObject::removeStaticPropertiesToHide(false, 'translationInfos');
        } else {
            DefaultObject::addStaticPropertiesToHide(false, 'translationInfos');
        }
    }
}