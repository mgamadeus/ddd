<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems;

use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Validation\Constraints\Choice;

abstract class Photo extends MediaItem
{
    /** @var string|null The type of the mediaitem */
    #[Choice(choices: [self::TYPE_PHOTO])]
    public ?string $type = self::TYPE_PHOTO;

    public function outputImage(): void
    {
        // Ensure there's a body to output
        if (!$this->mediaItemContent->getBody()) {
            throw new NotFoundException('No image content available to display.');
        }

        // Set the content type header to the MIME type of the image
        header('Content-Type: ' . $this->mediaItemContent->fileFormat);

        // Output the image content
        echo $this->mediaItemContent->getBody();
        exit;
    }
}
