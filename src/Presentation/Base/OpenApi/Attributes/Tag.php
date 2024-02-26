<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Tag extends Base
{
    use BaseAttributeTrait;

    public ?string $name;
    #[HideProperty]
    public ?string $group = null;
    public ?string $description = null;
    public ?array $externalDocs = null;

    public function __construct(?string $name, ?string $group = null, ?string $description = null, ?string $externalDocs = null)
    {
        $this->name = $name;
        $this->group = $group;
        $this->description = $description;
        $this->setExternalDoc($externalDocs);
        parent::__construct();
    }

    public function setExternalDoc(string $externalDocs = null)
    {
        if (!$externalDocs) {
            return;
        }
        if (!$this->externalDocs) {
            $this->externalDocs = ['url' => $externalDocs];
        }
    }

    /**
     * completes data e.g. description from another Tag if present
     * @param Tag $other
     * @return void
     */
    public function fillMissingDataFromOtherTag(Tag &$other): void
    {
        $this->description = $other->description && !$this->description ? $other->description : $this->description;
        $this->setExternalDoc($other->externalDocs ? $other->externalDocs['url'] : null);
    }

    public function uniqueKey(): string
    {
        return $this->name;
    }

}