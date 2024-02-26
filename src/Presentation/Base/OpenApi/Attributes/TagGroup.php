<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use DDD\Domain\Base\Entities\ValueObject;

class TagGroup extends ValueObject
{
    public ?string $objectType = null;

    public string $name;
    public array $tags;

    public function __construct()
    {
        parent::__construct();
        $this->objectType = null;
    }

    public function addTag(string $tag){
        if (!isset($this->tags))
            $this->tags = [];
        if (!in_array($tag, $this->tags))
            $this->tags[] = $tag;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->name);
    }
}