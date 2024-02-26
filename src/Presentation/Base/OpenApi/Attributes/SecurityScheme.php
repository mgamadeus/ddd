<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

#[Attribute(Attribute::TARGET_CLASS)]
class SecurityScheme extends Base
{
    use BaseAttributeTrait;

    public ?string $securityScheme = null;
    public ?string $type = null;
    public ?string $in = null;
    public ?string $scheme = null;
    public ?string $bearerFormat = null;

    public function __construct(string $securityScheme, string $type, string $in, string $scheme, string $bearerFormat)
    {
        $this->securityScheme = $securityScheme;
        $this->type = $type;
        $this->in = $in;
        $this->scheme = $scheme;
        $this->bearerFormat = $bearerFormat;
    }
}