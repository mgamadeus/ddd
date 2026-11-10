<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\Crons;

use DDD\Domain\Common\Entities\Crons\Cron;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The description is validated against the size of its column: a text that is too long is a validation message,
 * not a database error.
 */
class CronDescriptionLengthTest extends TestCase
{
    public function testDescriptionIsLimitedToTheColumnSize(): void
    {
        $lengthAttributes = (new ReflectionProperty(Cron::class, 'description'))->getAttributes(Length::class);
        $this->assertCount(1, $lengthAttributes);
        /** @var Length $length */
        $length = $lengthAttributes[0]->newInstance();
        $this->assertSame(16, $length->min);
        $this->assertSame(255, $length->max);
    }
}
