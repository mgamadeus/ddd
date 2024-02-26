<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class RolesRequiredForUpdate
{
    use BaseAttributeTrait;

    public array $rolesRequiredForUpdate = [];

    /**
     * @param bool $blockRecursiveUpdate
     */
    public function __construct(
        string ...$rolesRequiredForUpdate
    ) {
        $this->rolesRequiredForUpdate = $rolesRequiredForUpdate;
    }
}