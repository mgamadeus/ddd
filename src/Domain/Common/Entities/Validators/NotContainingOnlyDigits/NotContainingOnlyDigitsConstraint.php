<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Validators\NotContainingOnlyDigits;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use Symfony\Component\Validator\Constraint;

/**
 * Not containing only digits constraint
 *
 * @Annotation
 */
#[Attribute]
class NotContainingOnlyDigitsConstraint extends Constraint
{
    use BaseAttributeTrait;

    public string $containsOnlyDigitsMessage = 'This field is not allowed to contain only digits.';

    public function __construct(array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);
    }
}