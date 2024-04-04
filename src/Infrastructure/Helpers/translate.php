<?php

use DDD\Domain\Base\Entities\Translatable\Translatable;

if (!function_exists('__')) {
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
    function __(
        string $translationKey,
        string $languageCode = null,
        string $countryCode = null,
        string $writingStyle = null,
        bool $useFallBack = false,
        array $placeholders = []
    ) {
        return Translatable::translateKey($translationKey, $languageCode, $countryCode, $writingStyle, $useFallBack, $placeholders);
    }
}