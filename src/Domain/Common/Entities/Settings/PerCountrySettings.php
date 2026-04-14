<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Settings;

/**
 * @method PerCountrySetting getByUniqueKey(string $uniqueKey)
 */
class PerCountrySettings extends Settings
{
    public function getSettingByCountryShortCode(string $countryShortCode): ?PerCountrySetting
    {
        return $this->getByUniqueKey(PerCountrySetting::uniqueKeyStatic($countryShortCode));
    }
}