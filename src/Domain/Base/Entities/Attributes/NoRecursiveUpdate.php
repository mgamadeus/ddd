<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class NoRecursiveUpdate
{
    use BaseAttributeTrait;
    public bool $blockRecursiveUpdate = false;

    /**
     * @param bool $blockRecursiveUpdate
     */
    public function __construct(
        bool $blockRecursiveUpdate = true
    ) {
        $this->blockRecursiveUpdate = $blockRecursiveUpdate;
    }
}