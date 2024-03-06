<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Parameter extends Base
{
    use BaseAttributeTrait;

    public const RESPONSE = 'response';
    public const BODY = 'body';
    public const POST = 'post';
    public const PATH = 'path';
    public const QUERY = 'query';
    public const COOKIE = 'cookie';
    public const HEADER = 'header';

    public string $in = 'query';

    public ?bool $required;

    public function __construct(string $in, ?bool $required = null)
    {
        $this->in = $in;
        if ($required) {
            $this->required = true;
        }
        parent::__construct();
    }

    public function isRequired(): bool
    {
        return isset($this->required) && $this->required;
    }
}