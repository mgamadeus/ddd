<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Validators\NotContainingEmail;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use Symfony\Component\Validator\Constraint;

/**
 * Not containing email constraint
 *
 * @Annotation
 */
#[Attribute]
class NotContainingEmailConstraint extends Constraint
{
    use BaseAttributeTrait;
    
    public string $containsEmailMessage = 'This field is not allowed to contain an e-mail.';

    public function __construct(array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);
    }
}