<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Validators\NotContainingUrl;

use DDD\Infrastructure\Libs\Config;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NotContainingUrlConstraintValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof NotContainingUrlConstraint) {
            throw new UnexpectedTypeException($constraint, NotContainingUrlConstraint::class);
        }
        if (!$value) return;

        if (!isset($value)) {
            return;
        }
        $topLevelDomains = Config::get('Validation.domains');
        $pattern = '/(^|[^@\.\w-])[-\w:.]{1,256}\.('.implode('|',$topLevelDomains).')(\b|$)/iu';

        if (preg_match($pattern, $value)) {
            $this->context->buildViolation($constraint->containsUrlMessage)->addViolation();
        }
    }
}