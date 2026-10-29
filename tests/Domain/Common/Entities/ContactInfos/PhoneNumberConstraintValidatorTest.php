<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\ContactInfos;

use DDD\Domain\Common\Entities\ContactInfos\PhoneNumber;
use DDD\Domain\Common\Entities\ContactInfos\PhoneNumberConstraint;
use DDD\Domain\Common\Entities\ContactInfos\PhoneNumberConstraintValidator;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * One invalid number is one violation — also when the possible-number fallback of VALIDATION_POSSIBLE does not apply
 * (it used to add the violation twice).
 */
class PhoneNumberConstraintValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ConstraintValidatorInterface
    {
        return new PhoneNumberConstraintValidator();
    }

    public function testAValidNumberHasNoViolation(): void
    {
        $this->validator->validate(new PhoneNumber('+49 30 901820', 'DE'), new PhoneNumberConstraint());

        $this->assertNoViolation();
    }

    public function testAnImpossibleNumberWithPossibleValidationIsOneViolation(): void
    {
        // US numbers have a fixed length: 123 is neither valid nor possible
        $phoneNumber = new PhoneNumber(countryShortCode: 'US');
        $phoneNumber->value = '123';
        $phoneNumber->validationLevel = PhoneNumber::VALIDATION_POSSIBLE;

        $this->validator->validate($phoneNumber, new PhoneNumberConstraint());

        $this->assertCount(1, $this->context->getViolations());
    }

    public function testAnInvalidNumberWithExactValidationIsOneViolation(): void
    {
        $phoneNumber = new PhoneNumber(countryShortCode: 'DE');
        $phoneNumber->value = '12';
        $phoneNumber->validationLevel = PhoneNumber::VALIDATION_EXACT;

        $this->validator->validate($phoneNumber, new PhoneNumberConstraint());

        $this->assertCount(1, $this->context->getViolations());
    }
}
