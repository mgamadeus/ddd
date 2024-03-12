<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\ContactInfos;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Common\Entities\ContactInfos\ContactInfo;
use DDD\Domain\Common\Entities\ContactInfos\PhoneNumberConstraint;
use DDD\Infrastructure\Validation\Constraints\Choice;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Throwable;

#[PhoneNumberConstraint]
class PhoneNumber extends ContactInfo
{
    public const DEFAULT_FORMAT = PhoneNumberFormat::E164;

    public const SCOPE_PHONE = 'PHONE';
    public const SCOPE_MOBILE_PHONE = 'MOBILEPHONE';
    public const SCOPE_ADDITIONAL_PHONE = 'ADDITIONALPHONE';
    public const SCOPE_FAX = 'FAX';

    /** @var string Validates if number is valid */
    public const VALIDATION_EXACT = 'VALIDATION_EXACT';

    /** @var string Validates if number is a possible number, this is a lay validation */
    public const VALIDATION_POSSIBLE = 'VALIDATION_POSSIBLE';


    /** @var string|null Type of ContactInfo */
    #[Choice(choices: [self::TYPE_PHONE])]
    public ?string $type = self::TYPE_PHONE;

    #[Choice(choices: [self::SCOPE_PHONE, self::SCOPE_MOBILE_PHONE, self::SCOPE_ADDITIONAL_PHONE, self::SCOPE_FAX])]
    public ?string $scope;
    
    /** @var string|null The phone number itself */
    public ?string $value;

    /** @var string|null The countryShortCode of the Numbers country */
    public ?string $countryShortCode;

    /** @var string|null Validation level of the PhoneNumber */
    #[Choice(choices: [self::VALIDATION_EXACT, self::VALIDATION_POSSIBLE])]
    public ?string $validationLevel = self::VALIDATION_POSSIBLE;

    public function __construct(?string $phoneNumber = null, string $countryShortCode = null, $scope = self::SCOPE_PHONE)
    {
        if ($phoneNumber)
            $this->setAndNormalizePhoneNumber($phoneNumber, countryShortCode: $countryShortCode);
        if ($countryShortCode)
            $this->countryShortCode = $countryShortCode;
        $this->scope = $scope;
        return parent::__construct();
    }


    /**
     * Sets and normalizes the phone number
     * @param string $number
     * @param string $scope
     * @param string|null $countryShortCode
     * @return void
     * @throws NumberParseException
     */
    public function setAndNormalizePhoneNumber(string $number, string $scope = self::SCOPE_PHONE, string $countryShortCode = null): void
    {
        $phoneUtil = PhoneNumberUtil::getInstance();
        $this->countryShortCode = $countryShortCode;
        $this->scope = $scope;

        // first we check if the number is already in an international format, in this case we ignore the countryShortCode
        try {
            // in case of the phone number beeing in an international format, this will not trigger an error, then we are done
            $phoneNumber = $phoneUtil->parse($number);
            $this->countryShortCode = strtolower($phoneUtil->getRegionCodeForCountryCode($phoneNumber->getCountryCode()));
            $this->value = $phoneUtil->format($phoneNumber, self::DEFAULT_FORMAT);
            return;
        }
        catch (Throwable $throwable){
        }
        try {
            $phoneNumber = $phoneUtil->parse($number, $countryShortCode ? strtoupper($countryShortCode) : null);

            if (!$phoneUtil->isValidNumber($phoneNumber)) {
                if (isset($countryShortCode)) {
                    // support for local numbers without city code
                    if ($phoneUtil->isPossibleNumber(
                        $number,
                        $countryShortCode ? strtoupper($countryShortCode) : null
                    )) {
                        $this->value = $phoneUtil->format($phoneNumber, PhoneNumberFormat::NATIONAL);
                        return;
                    }
                }
            }
            $this->value = $phoneUtil->format($phoneNumber, self::DEFAULT_FORMAT);
        }
        catch (Throwable $throwable){
            $this->value = trim($number);
        }
    }

    /**
     * @param string|null $number
     * @return string|null
     */
    public static function convertToYextNumber(?string $number = null): ?string
    {
        if (!$number) {
            return null;
        }
        $finalNumber = substr($number, 1);
        return substr($finalNumber, 0, 2) . '-' . substr($finalNumber, 2, 3) . '-' . substr($finalNumber, 5);
    }

    /**
     * @param string $value
     * @param string|null $countryShortCode
     * @return void
     * @throws NumberParseException
     */
    public function normalizeAndSetValue(string $value, string $countryShortCode = null): void
    {
        $this->setAndNormalizePhoneNumber($value, countryShortCode: $countryShortCode);
    }

    /**
     * @param PhoneNumber|null $other
     * @return bool
     */
    public function isEqualTo(?DefaultObject $other = null): bool
    {
        return $this->value == $other->value && $this->scope == $other->scope;
    }
}
