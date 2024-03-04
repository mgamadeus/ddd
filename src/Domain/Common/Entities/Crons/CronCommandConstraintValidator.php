<?php

namespace DDD\Domain\Common\Entities\Crons;

use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Cron command validator
 */
class CronCommandConstraintValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof CronCommandConstraint) {
            throw new UnexpectedTypeException($constraint, CronCommandConstraint::class);
        }

        if (null === $value || '' === $value) {
            return;
        }
        $parts = explode(' ', trim($value), 2);
        $commandName = $parts[0];

        /** @var Application $application */
        $application = DDDService::instance()->createConsoleApplicationForCurrentKernel();
        try {
            $command = $application->find($commandName);
        } catch (CommandNotFoundException $t) {
            $command = null;
        }
        if (null === $command) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
}