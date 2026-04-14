<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Settings;

abstract class MergeableSetting extends Setting
{
    /**
     * @param MergeableSetting $otherSetting
     * @return void
     */
    abstract public function mergeFromOtherSetting(MergeableSetting &$otherSetting): void;
}