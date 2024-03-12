<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\ContactInfos;

use DDD\Domain\Common\Entities\ContactInfos\PhoneNumber;
use DDD\Domain\Common\Entities\ContactInfos\PhoneNumberConstraint;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Throwable;

/**
 * Phone number validator.
 */
class PhoneNumberConstraintValidator extends ConstraintValidator
{
    public const DEFAUL_FORMAT = PhoneNumberFormat::E164;

    /**
     * @throws NumberParseException
     */
    public function validate($phoneNumber, Constraint $constraint): void
    {
        if (!$constraint instanceof PhoneNumberConstraint) {
            throw new UnexpectedTypeException($constraint, PhoneNumberConstraint::class);
        }
        if (!($phoneNumber ?? null) || !($phoneNumber->value ?? null)) {
            return;
        }
        if (!$phoneNumber instanceof PhoneNumber) {
            return;
        }

        $phoneUtil = PhoneNumberUtil::getInstance();
        $countryShortCode = isset($phoneNumber->countryShortCode) ? strtoupper($phoneNumber->countryShortCode) : null;
        try {
            /** @var PhoneNumber $value */
            // first we check if the number is already in an international format, in this case we ignore the countryShortCode
            try {
                // in case of the phone number beeing in an international format, this will not trigger an error, then we are done
                $parsedPhoneNumber = $phoneUtil->parse($phoneNumber->value);
                if ($phoneUtil->isValidNumber($parsedPhoneNumber)) {
                    return;
                }
            } catch (NumberParseException) {
            }
            $parsedPhoneNumber = $phoneUtil->parse($phoneNumber->value, $countryShortCode);
            if (!$phoneUtil->isValidNumber($parsedPhoneNumber)) {
                if ($countryShortCode && $phoneNumber->validationLevel == PhoneNumber::VALIDATION_POSSIBLE) {
                    // support for local numbers without city code
                    if ($phoneUtil->isPossibleNumber(
                        $parsedPhoneNumber,
                        $countryShortCode
                    )) {
                        return;
                    }
                    $this->context->buildViolation($constraint->message)->addViolation();
                }
                $this->context->buildViolation($constraint->message)->addViolation();
            }
        } catch (Throwable $throwable) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}