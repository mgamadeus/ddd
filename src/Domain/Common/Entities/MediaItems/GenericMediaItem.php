<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * @property MediaItemContent $mediaItemContent
 */
class GenericMediaItem extends ValueObject
{
    /** @var string Photo type for media item */
    public const TYPE_PHOTO = 'PHOTO';

    /** @var string Video type for media item */
    public const TYPE_VIDEO = 'VIDEO';

    /** @var string Document type for media item */
    public const TYPE_DOCUMENT = 'DOCUMENT';

    /** @var string|null Public URL of the mediaitem */
    public ?string $publicUrl;

    /** @var string|null Description can be displayed on directories */
    public ?string $description;

    /** @var string|null The type of the mediaitem */
    #[Choice(choices: [self::TYPE_PHOTO, self::TYPE_VIDEO, self::TYPE_DOCUMENT])]
    public ?string $type;

    public GenericMediaItemContent $mediaItemContent;

    public function __construct()
    {
        parent::__construct();
        $this->mediaItemContent = new GenericMediaItemContent();
        $this->addChildren($this->mediaItemContent);
    }

    /**
     * @return string
     */
    public function uniqueKey(): string
    {
        $id = '';
        if (isset($this->publicUrl)) {
            $id = $this->publicUrl;
        }
        return self::uniqueKeyStatic($id);
    }

    /**
     * Returns the full public url of the Media Item, according to the environment from which the request is being processed
     * @return string
     */
    public function getFullPublicUrl(): string
    {
        if ($this->publicUrl === null) {
            return '';
        }

        // Check if the URL is absolute by looking for a scheme
        if (parse_url($this->publicUrl, PHP_URL_SCHEME) !== null) {
            return $this->publicUrl;
        }

        // If the URL is relative, prepend the PUBLIC_URL
        return Config::getEnv('PUBLIC_URL') . $this->publicUrl;
    }
}
