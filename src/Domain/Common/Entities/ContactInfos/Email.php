<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\ContactInfos;

use DDD\Domain\Common\Entities\ContactInfos\ContactInfo;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints as Assert;

class Email extends ContactInfo
{
    /** @var string Business Email Scope */
    public const SCOPE_EMAIL_BUSINESS = 'BUSINESS';
    /** @var string Email used for reports */
    public const SCOPE_EMAIL_REPORTS = 'REPORTS';
    /** @var string Email used for invoices */
    public const SCOPE_EMAIL_INVOICE = 'INVOICE';

    #[Choice(choices: [self::TYPE_EMAIL])]
    public ?string $type = self::TYPE_EMAIL;

    /** @var string|null Email's scope, e.g. BUSINESS */
    #[Choice(choices: [self::SCOPE_EMAIL_BUSINESS, self::SCOPE_EMAIL_REPORTS, self::SCOPE_EMAIL_INVOICE])]
    public ?string $scope;

    /** @var string|null The email itself */
    #[Assert\Email]
    public ?string $value;

    public function __construct(?string $email = null)
    {
        parent::__construct();
        if ($email) {
            $this->value = $this->normalize($email);
        }
    }


    /**
     * @param string $value
     * @return void
     */
    public function normalizeAndSetValue(string $value): void
    {
        $this->value = $this->normalize($value);
    }

    /**
     * @return string Returns local part of the Email Address
     */
    public function getLocalPart(): string
    {
        return substr($this->value, 0, strpos($this->value, '@'));
    }
}
