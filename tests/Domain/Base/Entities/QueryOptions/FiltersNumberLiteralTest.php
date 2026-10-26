<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use PHPUnit\Framework\TestCase;

/**
 * An unquoted number literal: the digit 0 is falsy in PHP and was not recognised as a number at all, and a number at
 * the end of the input called ctype_*() on null (a deprecation on every such query).
 */
class FiltersNumberLiteralTest extends TestCase
{
    public function testAnUnquotedZeroParses(): void
    {
        $this->assertSame(0.0, FiltersOptions::fromString('isActive eq 0')->value);
        $this->assertSame([0, 1], FiltersOptions::fromString('id in (0,1)')->value);
    }

    public function testANumberAtTheEndOfTheInputRaisesNoDeprecation(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $type, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;
            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);
        try {
            $filtersOptions = FiltersOptions::fromString('amount eq 10');
        } finally {
            restore_error_handler();
        }

        $this->assertSame(10.0, $filtersOptions->value);
        $this->assertSame([], $deprecations);
    }
}
