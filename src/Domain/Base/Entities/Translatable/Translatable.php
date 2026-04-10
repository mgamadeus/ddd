<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Translatable;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Domain\Base\Services\TranslatableService;
use DDD\Infrastructure\Validation\Constraints\Choice;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Translatable extends ValueObject
{
    use BaseAttributeTrait;

    /**
     * If enabled, the database model generator creates a stored virtual search column `virtual{Field}Search`
     * and a FULLTEXT index on it.
     */
    public bool $fullTextIndex = false;

    /**
     * Returns the stored virtual column name used for FULLTEXT indexing of JSON-backed translatable fields.
     */
    public static function getFullTextSearchVirtualColumnName(string $propertyName): string
    {
        return 'virtual' . ucfirst($propertyName) . 'Search';
    }

    public const WRITING_STYLE_FORMAL = 'FORMAL';
    public const WRITING_STYLE_INFORMAL = 'INFORMAL';
    public const DEFAULT_WRITING_STYLE = self::WRITING_STYLE_FORMAL;
    public const DEFAULT_LANGUAGE_CODE = 'en';

    /** @var string */
    public static string $currentLanguageCode;

    /** @var string|null */
    public static ?string $currentCountryCode;

    /** @var string[] Active language codes */
    public static array $activeLanguageCodes;

    /** @var string[] Active locales codes */
    public static array $activeLocales;

    /** @var string */
    #[Choice([self::WRITING_STYLE_FORMAL, self::WRITING_STYLE_INFORMAL])]
    public static string $currentWritingStyle;

    /** @var string */
    public static string $defaultLanguageCode;

    /** @var string */
    #[Choice([self::WRITING_STYLE_FORMAL, self::WRITING_STYLE_INFORMAL])]
    public static string $defaultWritingStyle;

    /** @var bool */
    public static bool $fallbackToDefaultLanguageCode;

    /** @var bool */
    public static bool $fallbackToNativeValue;

    /** @var array|null Snapshot of translation settings */
    protected static ?array $translationSettingsSnapshot = null;

    protected static function getTranslatableService(): TranslatableService
    {
        /** @var TranslatableService $translatableService */
        $translatableService = DDDService::instance()->getService(TranslatableService::class);
        return $translatableService;
    }

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

    public static function isCurrentLanguageSet(): bool
    {
        return isset(self::$currentLanguageCode);
    }

    public static function fallbackToDefaultLanguageIfNoTranslationIsPresent(): bool
    {
        return static::getTranslatableService()->fallbackToDefaultLanguageIfNoTranslationIsPresent();
    }

    public static function fallbackToNativeValueIfNoTranslationIsPresent(): bool
    {
        return static::getTranslatableService()->fallbackToNativeValueIfNoTranslationIsPresent();
    }

    public static function getDefaultLanguageCode(): string
    {
        return static::getTranslatableService()->getDefaultLanguageCode();
    }

    /**
     * Returns the set of active locales as an EntitySet.
     * @return EntitySet|null
     */
    public static function getActiveLocalesSet(): ?EntitySet
    {
        return static::getTranslatableService()->getActiveLocalesSet();
    }

    public static function getActiveLanguageCodes(): array
    {
        return static::getTranslatableService()->getActiveLanguageCodes();
    }

    public static function getActiveLocales(): array
    {
        return static::getTranslatableService()->getActiveLocales();
    }

    public static function isSupportedLanguageCode(string $languageCode): bool
    {
        return static::getTranslatableService()->isSupportedLanguageCode($languageCode);
    }

    public static function getDefaultWritingStyle(): string
    {
        return static::getTranslatableService()->getDefaultWritingStyle();
    }

    public static function getCurrentLanguageCode(): string
    {
        return static::getTranslatableService()->getCurrentLanguageCode();
    }

    public static function getCurrentWritingStyle(): string
    {
        return static::getTranslatableService()->getCurrentWritingStyle();
    }

    public static function getCurrentCountryCode(): ?string
    {
        return static::getTranslatableService()->getCurrentCountryCode();
    }

    public static function setCurrentLanguageCode(string $languageCode): void
    {
        self::$currentLanguageCode = strtolower($languageCode);
    }

    public static function setCurrentWritingStyle(string $writingStyle): void
    {
        self::$currentWritingStyle = $writingStyle;
    }

    public static function setCurrentCountryCode(?string $countryCode): void
    {
        self::$currentCountryCode = $countryCode !== null ? strtolower($countryCode) : null;
    }

    public static function getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null
    ): string {
        return static::getTranslatableService()->getTranslationIndexForLanguageCodeCountryCodeAndWritingStyle(
            $languageCode,
            $countryCode,
            $writingStyle
        );
    }

    public static function translateKey(
        string $translationKey,
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null,
        array $placeholders = []
    ): string {
        return static::getTranslatableService()->translateKey(
            $translationKey,
            $languageCode,
            $countryCode,
            $writingStyle,
            $placeholders
        );
    }

    /**
     * Replaces placeholders in the input string with values from an associative array.
     * Placeholders are expected to be in the format %varname%.
     */
    public static function replacePlaceholders(string $input, array $placeholders): string
    {
        foreach ($placeholders as $placeholder => $value) {
            $input = str_replace("%$placeholder%", (string)$value, $input);
        }
        return $input;
    }

    public static function setTranslationInfosVisibility(bool $visible): void
    {
        if ($visible) {
            DefaultObject::removeStaticPropertiesToHide(false, 'translationInfos');
        } else {
            DefaultObject::addStaticPropertiesToHide(false, 'translationInfos');
        }
    }

    public function __construct(bool $fullTextIndex = false)
    {
        $this->fullTextIndex = $fullTextIndex;
        parent::__construct();
    }
}
