<?php

namespace DDD\Domain\Common\Entities\MediaItems;

use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;

abstract class Document extends MediaItem
{
    /** @var string|null The type of the media item */
    #[Choice(choices: [self::TYPE_DOCUMENT])]
    public ?string $type = self::TYPE_DOCUMENT;

    /** @var string The filename of the document (wihtout path) */
    public string $fileName;

    /** @var DateTime The creation time */
    public DateTime $createdDateTime;

    /** @var DateTime The modified time */
    public DateTime $modifiedDateTime;
}