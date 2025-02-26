<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Validators\NotContainingUrlPrefix;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use Symfony\Component\Validator\Constraint;

/**
 * Not containing URL prefix constraint
 *
 * @Annotation
 */
#[Attribute]
class NotContainingUrlPrefixConstraint extends Constraint
{
    use BaseAttributeTrait;

    public string $containsURLPrefixMessage = 'This field is not allowed to contain an URL as a prefix.';

    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);
    }
}