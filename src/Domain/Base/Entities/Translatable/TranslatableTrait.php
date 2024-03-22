<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Translatable;

use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

trait TranslatableTrait
{
    /**
     * @var TranslationInfos|null Holds information about translations
     */
    #[DatabaseColumn(ignoreProperty: true)]
    public ?TranslationInfos $translationInfos;

    public function getTranslationInfos():TranslationInfos {
        if (isset($this->translationInfos))
            return $this->translationInfos;
        $this->translationInfos = new TranslationInfos();
        $this->translationInfos->addChildren($this->translationInfos);
        $this->translationInfos->currentLanguageCode = Translatable::getCurrentLanguageCode();
        $this->translationInfos->currentLocale = Translatable::getCurrentLocale();
        $this->translationInfos->currentWritingStyle = Translatable::getCurrentWritingStyle();

        $this->translationInfos->defaultLanguageCode = Translatable::getDefaultLanguageCode();
        $this->translationInfos->defaultLocale = Translatable::getDefaultLocale();
        $this->translationInfos->defaultWritingStyle = Translatable::getDefaultWritingStyle();
        return $this->translationInfos;
    }
}