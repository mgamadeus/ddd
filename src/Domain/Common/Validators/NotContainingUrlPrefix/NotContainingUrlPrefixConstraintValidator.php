<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Validators\NotContainingUrlPrefix;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NotContainingUrlPrefixConstraintValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof NotContainingUrlPrefixConstraint) {
            throw new UnexpectedTypeException($constraint, NotContainingUrlPrefixConstraint::class);
        }
        if (!$value) return;

        if (!isset($value)) {
            return;
        }

        // Checks if text contains an URL prefix (www. , http://, https://) but accepts URL suffixes( .com, .net)
        if (preg_match('/^(?:https?:\/\/|www.)/i', $value)) {
            $this->context->buildViolation($constraint->containsURLPrefixMessage)->addViolation();
        }
    }
}