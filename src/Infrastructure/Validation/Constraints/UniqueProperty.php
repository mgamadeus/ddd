<?php

namespace DDD\Infrastructure\Validation\Constraints;

use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\InvalidArgumentException;

/**
 * Constraint attribute that validates a property value is unique across all entities of the same type in the database.
 *
 * When applied to an entity property via the #[UniqueProperty] attribute, this constraint triggers a database
 * query during validation to ensure no other entity of the same type has the same value for the annotated property.
 * If the entity already has an ID, it is excluded from the uniqueness check to allow updates without false violations.
 *
 * Uses {@see UniquePropertyValidator} as its validation logic.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class UniqueProperty extends Constraint
{
    use BaseAttributeTrait;

    /**
     * Initializes the UniqueProperty constraint with optional validation groups and payload.
     *
     * @param array|null $groups The validation groups this constraint belongs to
     * @param mixed      $payload An optional payload for external processing of the constraint
     */
    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);
    }
}
