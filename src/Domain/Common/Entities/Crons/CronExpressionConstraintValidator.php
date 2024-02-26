<?php

namespace DDD\Domain\Common\Entities\Crons;

use Cron\CronExpression;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Cron expression validator.
 */
class CronExpressionConstraintValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof CronExpressionConstraint) {
            throw new UnexpectedTypeException($constraint, CronExpressionConstraint::class);
        }

        if (null === $value || '' === $value) {
            return;
        }
        if (!CronExpression::isValidExpression($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
}