<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Validation\Constraints;

use DDD\Infrastructure\Validation\Constraints\Choice;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Validator\Constraints\Choice as SymfonyChoice;

class ChoiceCallbackProbe
{
    public static int $calls = 0;

    /** @return string[] */
    public static function getAllowedValues(): array
    {
        self::$calls++;
        return ['DRAFT', 'ACTIVE'];
    }

    /** Stands for a callback that needs the container — which does not exist while the container compiles. */
    public static function getAllowedValuesFromTheContainer(): array
    {
        throw new RuntimeException('no container at compile time');
    }
}

/**
 * Symfony >= 7.4 instantiates constraint attributes while compiling the container in prod mode. A Choice whose
 * callback reaches the container must therefore be constructible without running the callback; the allowed values
 * are resolved when something actually asks for them, at runtime.
 */
class ChoiceCallbackIsLazyTest extends TestCase
{
    protected function setUp(): void
    {
        ChoiceCallbackProbe::$calls = 0;
    }

    public function testConstructingTheAttributeDoesNotRunTheCallback(): void
    {
        new Choice(callback: [ChoiceCallbackProbe::class, 'getAllowedValuesFromTheContainer']);

        $this->addToAssertionCount(1);
    }

    public function testTheCallbackIsResolvedOnFirstUseAndKept(): void
    {
        $choice = new Choice(callback: [ChoiceCallbackProbe::class, 'getAllowedValues']);
        $this->assertSame(0, ChoiceCallbackProbe::$calls);

        $this->assertSame(['DRAFT', 'ACTIVE'], Choice::getChoicesResolvedFromConstraint($choice));
        $this->assertSame(['DRAFT', 'ACTIVE'], Choice::getChoicesResolvedFromConstraint($choice));
        $this->assertSame(1, ChoiceCallbackProbe::$calls);
    }

    public function testStaticChoicesAreReturnedAsTheyAre(): void
    {
        $this->assertSame(['A', 'B'], Choice::getChoicesResolvedFromConstraint(new Choice(choices: ['A', 'B'])));
    }

    /** OpenAPI and the filter definitions also meet Symfony's own Choice; the helper serves both. */
    public function testAPlainSymfonyChoiceIsResolvedToo(): void
    {
        $symfonyChoice = new SymfonyChoice(callback: [ChoiceCallbackProbe::class, 'getAllowedValues']);

        $this->assertSame(['DRAFT', 'ACTIVE'], Choice::getChoicesResolvedFromConstraint($symfonyChoice));
    }

    /** A bare method name is resolved by the validator against the validated object, never here. */
    public function testABareMethodNameYieldsAnEmptyList(): void
    {
        $this->assertSame([], Choice::getChoicesResolvedFromConstraint(new Choice(callback: 'getAllowedValues')));
    }
}
