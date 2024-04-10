<?php

namespace DDD\Domain\Common\Entities\MediaItems;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\Exception;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\PhotoUtils\PhotoUtils;
use DDD\Infrastructure\Traits\Serializer\Attributes\DontPersistProperty;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use Imagick;
use ImagickException;

/**
 * @method MediaItem getParent()
 */
abstract class MediaItemContent extends Entity
{
    /** @var string|null */
    public ?string $base64EncodedContent;

    /** @var int|null Height in pixels */
    public ?int $height;

    /** @var int|null Width in pixels */
    public ?int $width;

    /** @var float|null File size in bytes */
    public ?float $fileSize;

    /** @var string|null File format */
    public ?string $fileFormat;

    /** @var string|null Body content */
    #[HideProperty, DontPersistProperty]
    public ?string $body;

    /** @var string|null Name of the mediaItem */
    public ?string $name;

    /** @var bool|null If true, photo uses compression */
    public ?bool $compression = false;

    /** @var string|null Mediaitem external id */
    public ?string $externalId;

    /**
     * @return bool|string|null Returns image blog based from base64 encoded content
     */
    public function getImageBlob(): bool|string|null
    {
        if (!isset($this->base64EncodedContent)) {
            return null;
        }

        if (str_contains($this->base64EncodedContent, ',')) {
            return base64_decode(PhotoUtils::getEncodedImageString($this->base64EncodedContent));
        }

        return base64_decode($this->base64EncodedContent);
    }

    public function getScope(): ?string
    {
        return $this->getParent()->scope ?? null;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->getParent()?->id);
    }

    /**
     * @throws Exception
     */
    public function populateMediaItemContentInfo(): void
    {
        $decodedImage = $this->getImageBlob();
        $imageInfo = PhotoUtils::getImageInfoFromString($decodedImage);

        if (!$imageInfo) {
            throw new NotFoundException('Invalid image format');
        }

        [$this->width, $this->height] = PhotoUtils::getImageWidthAndHeightFromString(
            base64_decode($this->base64EncodedContent)
        );
        $this->fileSize = mb_strlen($decodedImage, '8bit');
        $this->fileFormat = $imageInfo['mime'];
    }

    /**
     * Populate media item content information from an Imagick instance
     *
     * @param Imagick $imagickInstance The Imagick instance to extract image information from
     * @throws Exception
     */
    public function populateMediaItemContentInfoFromImagick(Imagick $imagickInstance): void
    {
        // Get image dimensions
        $this->width = $imagickInstance->getImageWidth();
        $this->height = $imagickInstance->getImageHeight();

        // Get the image format (MIME type)
        $this->fileFormat = $imagickInstance->getImageMimeType();

        // Get the image blob and calculate its size
        $this->body = $imagickInstance->getImageBlob();

        $this->fileSize = strlen($this->body); // File size in bytes
    }

    /**
     * @return string Retuns base64 encoded content
     */
    public function getBase64EncodedContent(): ?string
    {
        if (isset($this->base64EncodedContent)) {
            return $this->base64EncodedContent;
        }
        if (!isset($this->body)) {
            return null;
        }
        $this->base64EncodedContent = base64_encode($this->body);
        return $this->base64EncodedContent;
    }

    /**
     * Loads MediaItemContent from a local file or URL
     * @param string $source Path to the local file or URL
     * @return void
     * @throws Exception
     * @throws NotFoundException
     * @throws ImagickException
     */
    public function loadFromSource(string $source = null): void
    {
        if (!$source) {
            $source = $this->getParent()->publicUrl ?? null;
        }
        if (!$source) {
            throw new NotFoundException('No source specified');
        }
        $contentIsUrl = strpos($source, 'http://') === 0 || strpos($source, 'https://') === 0;

        // Check if the source is a URL or a local file
        if ($contentIsUrl) {
            // Use get_headers to check if the URL exists (only if it's a URL)
            $headers = @get_headers($source);
            if ($headers === false || strpos($headers[0], '404') !== false) {
                throw new NotFoundException("URL not found: $source");
            }
        } elseif (!file_exists($source)) {
            // Check if the local file exists

            throw new NotFoundException("File not found: $source");
        }

        // Get the content from the URL or local file
        $content = @file_get_contents($source);
        if ($content === false) {
            throw new NotFoundException(
                $contentIsUrl ? "Unable to read from URL: $source" : "Unable to read file: $source"
            );
        }

        // Load the content into Imagick
        $imagick = new Imagick();
        try {
            $imagick->readImageBlob($content);
        } catch (ImagickException $e) {
            throw new NotFoundException('Unable to process image content: ' . $e->getMessage());
        }

        // Populate media item content information from the Imagick instance
        $this->populateMediaItemContentInfoFromImagick($imagick);
    }

    public function getBody(): ?string
    {
        if (isset($this->body)) {
            return $this->body;
        }
        if (isset($this->base64EncodedContent)) {
            $this->body = base64_decode($this->base64EncodedContent);
            return $this->body;
        }
        return null;
    }

    public function onToObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true
    ): void {
        $this->getBase64EncodedContent();
        $this->body = null;
    }
}
