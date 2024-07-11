<?php

namespace DDD\Domain\Common\Entities\MediaItems;

use DDD\Domain\Base\Entities\Entity;

/**
 * @method MediaItem getParent()
 */
abstract class MediaItemContent extends Entity
{
    use MediaItemContentTrait;

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->getParent()?->id);
    }
}
