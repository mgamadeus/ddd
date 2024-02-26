<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

class OpenApi extends Base
{
    public ?string $version = null;

    public function __construct(string $version)
    {
        $this->version = $version;
    }
}