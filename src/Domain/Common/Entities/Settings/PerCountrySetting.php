<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Settings;

class PerCountrySetting extends MergeableSetting
{
    /** @var string|null The short code for the country, e.g. DE */
    public ?string $countryShortCode;

    /** Per Country ServiceSetting will return a key based on countryCode and Class Name */
    public function uniqueKey(): string
    {
        return PerCountrySetting::uniqueKeyStatic($this->countryShortCode);
    }

    /**
     * @param PerCountrySetting $otherSetting
     * @return void
     */
    public function mergeFromOtherSetting(MergeableSetting &$otherSetting): void
    {
        $this->countryShortCode = $otherSetting->countryShortCode;
    }

}