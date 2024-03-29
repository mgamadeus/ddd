<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems;

use DDD\Infrastructure\Validation\Constraints\Choice;

class PDFDocumentAsImage extends Photo
{
    /** @var string|null The type of the mediaitem */
    #[Choice(choices: [self::TYPE_PHOTO])]
    public ?string $type = self::TYPE_PHOTO;

    public PDFMediaItemContent $mediaItemContent;
}
