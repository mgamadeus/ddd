<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Validators\PunctuationLimit;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class PunctuationLimitConstraintValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof PunctuationLimitConstraint) {
            throw new UnexpectedTypeException($constraint, PunctuationLimitConstraint::class);
        }
        if (!$value) return;

        if (!isset($value)) {
            return;
        }

        $count = preg_match_all('/[[:punct:]]/', $value);
        if ($count > $constraint->maxPunctuations) {
            $this->context->buildViolation($constraint->tooManyPunctuationsMessage)->addViolation();
        }
    }
}