<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Parameter extends Base
{
    use BaseAttributeTrait;

    public const string RESPONSE = 'response';

    public const string BODY = 'body';

    public const string POST = 'post';

    public const string PATH = 'path';

    public const string QUERY = 'query';

    public const string COOKIE = 'cookie';

    public const string HEADER = 'header';

    public const string FILES = 'files';

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