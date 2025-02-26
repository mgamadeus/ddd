<?php

namespace DDD\Domain\Base\Entities\QueryOptions;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use Symfony\Component\Validator\Constraint;

/**
 * Phone number constraint.
 *
 * @Annotation
 */
#[Attribute]
class FilterOptionsConstraint extends Constraint
{
    use BaseAttributeTrait;

    public string $message = 'The given FilterOptions are invalid.';

    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);
    }

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }

}