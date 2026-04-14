<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Persons;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

class PersonGender extends ValueObject
{
    public const string GENDER_MALE = 'm';

    public const string GENDER_FEMALE = 'f';

    public const string GENDER_OTHER = 'd';

    public const string GENDER_ALL = 'a';

    /** @var string|null The persons gender */
    #[Choice(choices: [self::GENDER_MALE, self::GENDER_FEMALE, self::GENDER_OTHER, self::GENDER_ALL], message: 'persongender.gender.choice')]
    public ?string $gender = 'm';

    public function __construct(?string $gender = null)
    {
        $this->setGender($gender);
        parent::__construct();
    }

    public function getGender(): string
    {
        return $this->gender;
    }

    public function setGender(?string $gender): void
    {
        switch ($gender) {
            case self::GENDER_MALE:
                $this->gender = self::GENDER_MALE;
                break;
            case self::GENDER_FEMALE:
                $this->gender = self::GENDER_FEMALE;
                break;
            default:
                $this->gender = $gender;
        }
    }
}