<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

#[Attribute(Attribute::TARGET_METHOD)]
class Summary extends Base
{
    use BaseAttributeTrait;

    public ?string $summary = null;

    public function __construct(string $summary)
    {
        $this->summary = $summary;
    }
}