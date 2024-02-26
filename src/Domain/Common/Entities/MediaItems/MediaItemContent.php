<?php

namespace DDD\Domain\Common\Entities\MediaItems;

use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Exceptions\Exception;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
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
     * Loads MediaItemContent from file
     * @param string $fileName
     * @return void
     * @throws Exception
     * @throws NotFoundException
     * @throws ImagickException
     */
    public function loadFromFile(string $fileName): void
    {
        // Check if the file exists
        if (!file_exists($fileName)) {
            throw new NotFoundException("File not found: $fileName");
        }

        // Get the file content
        $fileContent = file_get_contents($fileName);

        // Check if the file is an image and get its information
        $imagick = new Imagick();
        try {
            $imagick->readImageBlob($fileContent);
        } catch (Exception $e) {
            throw new Exception('Unable to read image file: ' . $e->getMessage());
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
