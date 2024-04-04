<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MediaItems;

class GenericPhoto extends Photo
{
    public GenericMediaItemContent $mediaItemContent;

    public function __construct()
    {
        parent::__construct();
        $this->mediaItemContent = new GenericMediaItemContent();
        $this->addChildren($this->mediaItemContent);
    }

}