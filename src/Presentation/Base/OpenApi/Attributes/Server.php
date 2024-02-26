<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Server extends Base
{
    use BaseAttributeTrait;

    public ?string $url = null;

    public function __construct(string $url)
    {
        $this->url = $url;
    }
}