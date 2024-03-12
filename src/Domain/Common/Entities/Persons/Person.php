<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Persons;

use DDD\Domain\Base\Entities\ValueObject;

class Person extends ValueObject
{
    public ?string $lastName;
    public ?string $firstName;
    public ?string $title;
    public ?string $academicTitle;
    public ?string $jobTitle;
    public PersonGender $gender;

    public function __construct()
    {
        $this->gender = new PersonGender();
        parent::__construct();
    }

    /**
     * @return array Returns various name combinations for matching purposes
     */
    public function getNameCombinations(): array
    {
        $combinations = [];
        $firstName = isset($this->firstName) ? trim(
            strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $this->firstName))
        ) : '';
        $lastName = isset($this->lastName) ? trim(strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $this->lastName))) : '';


        // Replace spaces with hyphens to standardize the delimiter
        $firstName = str_replace(' ', '-', $firstName);

        // Split first names and generate combinations
        $firstNames = explode('-', $firstName);


        // add combinations for <full firstname> <lastname>
        $combinations[] = trim($firstName . ' ' . $lastName);

        // add combinations for <full firstname> <lastname>
        $combinations[] = trim($lastName . ' ' . $firstName);

        foreach ($firstNames as $name) {
            // add combinations for <firstname> <lastname>
            $combinations[] = trim($name . ' ' . $lastName);
            $combinations[] = trim($lastName . ' ' . $name);
        }
        return array_unique($combinations);
    }

}