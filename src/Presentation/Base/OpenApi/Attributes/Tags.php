<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use DDD\Domain\Base\Entities\BaseObject;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Traits\Serializer\Attributes\ExposePropertyInsteadOfClass;

/**
 * @property Tag[] $elements
 * @property Tag[] elementsByUniqueKey
 * @method Tag getByUniqueKey(string $uniqueKey)
 * @method Tag[] getElements()
 */
#[ExposePropertyInsteadOfClass('elements')]
class Tags extends ObjectSet
{
    public ?string $objectType = null;

    public function __construct()
    {
        parent::__construct();
        $this->objectType = null;
    }

    /**
     * adds alements, if element is already present, completes data from newly added tag
     * @param DefaultObject ...$elements
     * @return void
     */
    public function add(?BaseObject &...$elements): void
    {
        foreach ($elements as $element) {
            /** @var Tag $element */
            if (!$element) {
                continue;
            }
            if ($this->contains($element)) {
                $presentElement = $this->getByUniqueKey($element->uniqueKey());
                $presentElement->fillMissingDataFromOtherTag($element);
            } else {
                parent::add($element);
            }
        }
    }

    public function sortByName()
    {
        $this->sort(function (Tag $a, Tag $b) {
            return strnatcmp($a->name, $b->name);
        });
    }

    /**
     * @return void Removes all Schema Tags
     */
    public function removeSchemaTags(): void
    {
        foreach ($this->getElements() as $tag) {
            if ($tag->isSchemaTag()) {
                $this->remove($tag);
            }
        }
    }
}