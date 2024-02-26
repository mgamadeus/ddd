<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Info extends Base
{
    use BaseAttributeTrait;

    public ?string $title = null;
    public ?string $version = null;

    public function __construct(string $title, string $version = '1.0')
    {
        $this->title = $title;
        $this->version = $version;
    }
}