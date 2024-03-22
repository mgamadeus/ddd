<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Validators\NotContainingOnlyDigits;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NotContainingOnlyDigitsConstraintValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof NotContainingOnlyDigitsConstraint) {
            throw new UnexpectedTypeException($constraint, NotContainingOnlyDigitsConstraint::class);
        }

        if (!isset($value)) {
            return;
        }

        // Check if text has only digits and space
        $regex = '"^[0-9 ]+$"';
        if($value && preg_match($regex, $value)) {
            $this->context->buildViolation($constraint->containsOnlyDigitsMessage)->addViolation();
        }
    }
}