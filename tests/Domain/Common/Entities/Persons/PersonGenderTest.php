<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\Persons;

use DDD\Domain\Common\Entities\Persons\PersonGender;
use PHPUnit\Framework\TestCase;

class PersonGenderTest extends TestCase
{
    public function testAPersonWithoutGenderReturnsNull(): void
    {
        $this->assertNull((new PersonGender(null))->getGender());
        $this->assertSame(PersonGender::GENDER_FEMALE, (new PersonGender(PersonGender::GENDER_FEMALE))->getGender());
    }
}
