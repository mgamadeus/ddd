<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Persons;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

class PersonGender extends ValueObject
{
    /** @var string Male gender */
    public const string GENDER_MALE = 'm';

    /** @var string Female gender */
    public const string GENDER_FEMALE = 'f';

    /** @var string Other gender */
    public const string GENDER_OTHER = 'd';

    /** @var string All genders */
    public const string GENDER_ALL = 'a';

    /** @var string|null The persons gender */
    #[Choice(choices: [self::GENDER_MALE, self::GENDER_FEMALE, self::GENDER_OTHER, self::GENDER_ALL], message: 'persongender.gender.choice')]
    public ?string $gender = 'm';

    public function __construct(?string $gender = null)
    {
        $this->setGender($gender);
        parent::__construct();
    }

    /** The gender; null when none is set */
    public function getGender(): ?string
    {
        return $this->gender;
    }

    public function setGender(?string $gender): void
    {
        $this->gender = match ($gender) {
            self::GENDER_MALE => self::GENDER_MALE,
            self::GENDER_FEMALE => self::GENDER_FEMALE,
            default => $gender,
        };
    }
}