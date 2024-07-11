<?php

namespace DDD\Domain\Common\Entities\MediaItems;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * @method MediaItem getParent()
 */
class GenericMediaItemContent extends ValueObject
{
    use MediaItemContentTrait;
}
