<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * @property MediaItemContent $mediaItemContent
 */
abstract class MediaItem extends Entity
{
    /** @var string Photo type for media item */
    public const TYPE_PHOTO = 'photo';

    /** @var string Video type for media item */
    public const TYPE_VIDEO = 'video';

    /** @var string Document type for media item */
    public const TYPE_DOCUMENT = 'DOCUMENT';

    /** @var string|null Public URL of the mediaitem */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $publicUrl;

    /** @var string|null Description can be displayed on directories */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $description;

    /** @var int|null - Represents the number of views */
    public ?int $viewCount;

    /** @var string|null The type of the mediaitem */
    #[Choice(choices: [self::TYPE_PHOTO, self::TYPE_VIDEO, self::TYPE_DOCUMENT])]
    public ?string $type;

    /**
     * @return string
     */
    public function uniqueKey(): string
    {
        $id = null;
        if (isset($this->id)) {
            $id = $this->id;
        } elseif (isset($this->publicUrl)) {
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
        return Config::getEnv('PUBLIC_URL') . $this->publicUrl;
    }
}
