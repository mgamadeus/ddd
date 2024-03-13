<?php

namespace DDD\Domain\Common\Entities\ContactInfos;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use Symfony\Component\Validator\Constraint;

/**
 * Phone number constraint.
 *
 * @Annotation
 */
#[Attribute]
class PhoneNumberConstraint extends Constraint
{
    use BaseAttributeTrait;

    public string $message = 'The given value is not a valid phone number.';

    public function __construct(array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);
    }

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }

}