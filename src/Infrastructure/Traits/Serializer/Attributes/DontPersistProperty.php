<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits\Serializer\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

/**
 * If true, hides property when serialized for persistence
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class DontPersistProperty extends SerializerAttribute
{
    use BaseAttributeTrait;
}