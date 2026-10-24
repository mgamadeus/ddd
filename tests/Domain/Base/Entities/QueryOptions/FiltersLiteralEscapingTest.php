<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use PHPUnit\Framework\TestCase;

/**
 * A quoted filter literal knows two escapes, `\'` and `\\`; every other backslash is literal. The parser used to keep
 * the escape characters in the value, so `name eq 'O\'Brien'` searched for `O\'Brien` and a value ending in a
 * backslash could not be written at all.
 */
class FiltersLiteralEscapingTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function scalarLiterals(): array
    {
        return [
            'escaped quote' => ["name eq 'O\\'Brien'", "O'Brien"],
            'escaped quote mid-word' => ["name fb 'Villeneuve-d\\'Ascq'", "Villeneuve-d'Ascq"],
            'trailing backslash' => ["path eq 'C:\\\\'", 'C:\\'],
            'lone backslash stays' => ["name eq 'a\\b'", 'a\\b'],
            'double quotes inside' => ["name eq 'say \"hi\"'", 'say "hi"'],
        ];
    }

    /** @dataProvider scalarLiterals */
    public function testAScalarLiteralIsUnescaped(string $filterQuery, string $expectedValue): void
    {
        $this->assertSame($expectedValue, FiltersOptions::fromString($filterQuery)->value);
    }

    public function testAnArrayLiteralIsUnescaped(): void
    {
        $this->assertSame(["O'Brien", 'Anna', 'C:\\'], FiltersOptions::fromString("name in ['O\\'Brien','Anna','C:\\\\']")->value);
        $this->assertSame(['x"y', 'say "hi"'], FiltersOptions::fromString("name in [\"x\\\"y\",'say \"hi\"']")->value);
        $this->assertSame([1, 2, 3], FiltersOptions::fromString('id in (1,2,3)')->value);
    }

    public function testAnExpressionAfterAnEscapedLiteralStillParses(): void
    {
        $filtersOptions = FiltersOptions::fromString("name eq 'Villeneuve-d\\'Ascq' and id gt '5'");

        $this->assertSame("Villeneuve-d'Ascq", $filtersOptions->getExpressions()[0]->value);
        $this->assertSame('5', $filtersOptions->getExpressions()[1]->value);
    }

    /** @return array<string, array{0: mixed}> */
    public static function roundTripValues(): array
    {
        return [
            'apostrophe' => ["Villeneuve-d'Ascq"],
            'trailing backslash' => ['C:\\'],
            'backslash before quote' => ["x\\'y"],
            'plain' => ['Berlin'],
            'list' => [["O'Brien", 'C:\\']],
        ];
    }

    /** @dataProvider roundTripValues */
    public function testCreateExpressionSurvivesTheRoundTripThroughTheQueryString(mixed $value): void
    {
        $operator = is_array($value) ? FiltersOptions::OPERATOR_IN : FiltersOptions::OPERATOR_EQUAL;
        $filtersOptions = FiltersOptions::createExpression('name', $operator, $value);
        $this->assertSame($value, $filtersOptions->value);

        // what an API client receives and sends back as ?filters=…
        $queryString = http_build_query(['filters' => $filtersOptions->toCanonicalExpression()]);
        parse_str($queryString, $queryParameters);

        $this->assertSame($value, FiltersOptions::fromString($queryParameters['filters'])->value);
    }
}
