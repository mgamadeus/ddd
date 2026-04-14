<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Settings;

use DDD\Domain\Base\Entities\EntitySet;

/**
 * @property Setting[] $elements;
 * @method Setting getByUniqueKey(string $uniqueKey)
 * @method Setting[] getElements()
 * @method Setting first()
 */
class Settings extends EntitySet
{

    /**
     * Merges foreach setting merges data from corresponding other settings entry if present, e.g. AdsSetting > AdsSetting.
     * If a setting is not present here but in otherSettings, it is newly created and merged (in order to not have a reference of the other setting but a copy instead).
     * @param Settings $otherSettings
     * @return void
     */
    public function getSettingByType($type): ?Setting
    {
        return $this->getByUniqueKey($type);
    }

    public function mergeFromOtherSettings(Settings &$otherSettings): void
    {
        // first we look at all present settings and try to merge from other if existent
        foreach ($this->elements as $setting) {
            if ($otherSetting = $otherSettings->getByUniqueKey($setting->uniqueKey())) {
                if ($setting instanceof MergeableSetting && $otherSetting instanceof MergeableSetting) {
                    $setting->mergeFromOtherSetting($otherSetting);
                }
            }
        }
        // we now look at other and try to add non existent, as we already merged the existend ones
        foreach ($otherSettings->elements as $setting) {
            if (!$this->contains($setting)) {
                if (!($setting instanceof MergeableSetting)) {
                    continue;
                }
                $settingClass = $setting::class;
                /** @var MergeableSetting $newSetting */
                $newSetting = new $settingClass();
                $newSetting->mergeFromOtherSetting($setting);
                $this->add($newSetting);
            }
        }
    }
}