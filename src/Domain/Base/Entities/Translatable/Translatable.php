<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Translatable;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
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
     * The default locale used in the application.
     * @var string DEFAULT_LOCALE
     */
    public const DEFAULT_LOCALE = 'us';

    /**
     * The current language code.
     * @var string
     */
    public static string $currentLanguageCode;

    /**
     * The current locale of the application.
     * @var string
     */
    public static string $currentLocale;

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
     * The default locale used in the application.
     * @var string
     */
    public static string $defaultLocale;

    /**
     * The default writing style used in the application.
     * @var string
     */
    #[Choice([self::WRITING_STYLE_FORMAL, self::WRITING_STYLE_INFORMAL])]
    public static string $defaultWritingStyle;

    /**
     * Gets the default language code.
     *
     * If the default language code is set, it will return the value.
     * Otherwise, it will check for the TRANSLATABLE_DEFAULT_LANGUAGE_CODE environment variable
     * and if found, set it as the default language code.
     * If the environment variable is not set, it will use the DEFAULT_LANGUAGE_CODE constant as the default value.
     * @return string The default language code.
     */
    public static function getDefaultLanguageCode():string {
        if (isset(static::$defaultLanguageCode))
            return static::$defaultLanguageCode;
        if ($defaultLanguageCode = Config::getEnv('TRANSLATABLE_DEFAULT_LANGUAGE_CODE'))
            static::$defaultLanguageCode = $defaultLanguageCode;
        else
            static::$defaultLanguageCode = static::DEFAULT_LANGUAGE_CODE;
        return static::$defaultLanguageCode;
    }

    /**
     * Retrieves the default writing style.
     * @return string The default writing style.
     */
    public static function getDefaultWritingStyle():string {
        if (isset(static::$defaultWritingStyle))
            return static::$defaultWritingStyle;
        if ($defaultWritingStyle = Config::getEnv('TRANSLATABLE_DEFAULT_WRITING_STYLE'))
            static::$defaultWritingStyle = $defaultWritingStyle;
        else
            static::$defaultWritingStyle = static::DEFAULT_WRITING_STYLE;
        return static::$defaultWritingStyle;
    }

    /**
     * Returns the default locale.
     *
     * If the default locale is already set, it will be returned.
     * If the default locale is not set, it will check if there is a value for 'TRANSLATABLE_DEFAULT_LOCALE'
     * in the environment configuration. If a value exists, it will set the default locale to that value.
     * If no value exists, it will set the default locale to the value of static::DEFAULT_LOCALE.
     * @return string The default locale.
     */
    public static function getDefaultLocale():string {
        if (isset(static::$defaultLocale))
            return static::$defaultLocale;
        if ($defaultLocale = Config::getEnv('TRANSLATABLE_DEFAULT_LOCALE'))
            static::$defaultLocale = $defaultLocale;
        else
            static::$defaultLocale = static::DEFAULT_LOCALE;
        return static::$defaultLocale;
    }

    /**
     * Returns the current language code.
     *
     * If the current language code has been previously set, it is returned. Otherwise, the default language code is retrieved
     * and set as the current language code before returning it.
     * @return string The current language code.
     */
    public static function getCurrentLanguageCode():string {
        if (isset(static::$currentLanguageCode))
            return static::$currentLanguageCode;
        static::$currentLanguageCode = static::getDefaultLanguageCode();
        return static::$currentLanguageCode;
    }

    /**
     * Retrieves the current writing style.
     *
     * If the current writing style is set, it will be returned.
     * Otherwise, it will be set to the default writing style.
     *
     * @return string The current writing style.
     */
    public static function getCurrentWritingStyle():string {
        if (isset(static::$currentWritingStyle))
            return static::$currentWritingStyle;
        static::$currentWritingStyle = static::getDefaultWritingStyle();
        return static::$currentWritingStyle;
    }

    /**
     * Retrieves the current locale.
     * This method returns the current locale if it's already set. If not, it sets the current locale to the default locale
     * and then returns it.
     * @return string The current locale.
     */
    public static function getCurrentLocale():string {
        if (isset(static::$currentLocale))
            return static::$currentLocale;
        static::$currentLocale = static::getDefaultLocale();
        return static::$currentLocale;
    }

    /**
     * Sets the current language code.
     * @param string $languageCode The language code to set.
     * @return void
     */
    public function setCurrentLanguageCode(string $languageCode): void {
        static::$currentLanguageCode = $languageCode;
    }

    /**
     * Sets the current writing style.
     * @param string $writingStyle The new writing style to set.
     * @return void
     */
    public function setCurrentWritingStyle(string $writingStyle): void {
        static::$currentWritingStyle = $writingStyle;
    }

    /**
     * Set the current locale.
     * @param string $locale The locale to set.
     * @return void
     */
    public function setCurrentLocale(string $locale): void {
        static::$currentLocale = $locale;
    }

    /**
     * Returns the Key under wich to store the translation based on languageCode, locale and writingStyle, if any of these are not provided, default values are used
     * @param string|null $languageCode
     * @param string|null $locale
     * @param string|null $writingStyle
     * @return string
     */
    public static function getTranslationKeyForLanguageCodeLocaleAndWritingStyle(?string $languageCode = null, ?string $locale = null, ?string $writingStyle = null):string {
        $languageCode = $languageCode ?? static::getCurrentLanguageCode();
        $locale = $languageCode ?? static::getCurrentLanguageCode();
        $writingStyle = $writingStyle ?? static::getCurrentWritingStyle();
        return $languageCode . ':' . $locale . ':' . $writingStyle;
    }
}