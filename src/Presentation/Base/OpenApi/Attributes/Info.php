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

    /**
     * OpenApi `info.description` (Markdown). Not set via the attribute constructor — populated programmatically by the
     * Document builder (e.g. to emit the shared QueryOptions grammar once per document). Serialized automatically.
     */
    public ?string $description = null;

    public function __construct(string $title, string $version = '1.0')
    {
        $this->title = $title;
        $this->version = $version;
    }
}