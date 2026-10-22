<?php

declare (strict_types=1);

namespace DDD\Infrastructure\Validation\Constraints;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use Symfony\Component\Validator\Constraints\Choice as SymfonyChoice;

use function is_array;
use function is_callable;

/**
 * Symfony's Choice constraint, usable as a DDD attribute.
 *
 * A `callback` is NEVER evaluated in the constructor. Symfony >= 7.4 instantiates constraint attributes while
 * COMPILING the container in prod mode (FrameworkBundle autoconfigures `Constraint` attributes unless kernel.debug),
 * and a callback such as `CacheScope::getCacheScopes()` reaches DDDService and the container, which does not exist
 * yet — the compile fatals. Validation resolves the callback itself (Symfony's ChoiceValidator); code that needs the
 * allowed values outside validation (OpenAPI enums, filter definitions, ENUM columns) asks
 * {@see self::getChoicesResolvedFromConstraint()}, which resolves the callback on first use, at runtime.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Choice extends SymfonyChoice
{
    use BaseAttributeTrait;

    public function __construct(
        array|string $options = [],
        ?array $choices = null,
        callable|string|null $callback = null,
        ?bool $multiple = null,
        ?bool $strict = null,
        ?int $min = null,
        ?int $max = null,
        ?string $message = null,
        ?string $multipleMessage = null,
        ?string $minMessage = null,
        ?string $maxMessage = null,
        ?array $groups = null,
        mixed $payload = null
    ) {
        parent::__construct(
            $options,
            $choices,
            $callback,
            $multiple,
            $strict,
            $min,
            $max,
            $message,
            $multipleMessage,
            $minMessage,
            $maxMessage,
            $groups,
            $payload
        );
    }

    /**
     * The allowed values of any Choice constraint (this one or Symfony's): its static `choices`, or — when it only
     * names a `callback` — the callback's result, resolved now and kept on the constraint for the next read. A
     * callback that is not callable on its own (a bare method name, which only the validator can resolve against
     * the validated object) yields an empty list.
     *
     * @param SymfonyChoice $choiceConstraint
     * @return array
     */
    public static function getChoicesResolvedFromConstraint(SymfonyChoice $choiceConstraint): array
    {
        if ($choiceConstraint->choices === null && $choiceConstraint->callback !== null
            && is_callable($choiceConstraint->callback)) {
            $resolvedChoices = ($choiceConstraint->callback)();
            $choiceConstraint->choices = is_array($resolvedChoices) ? $resolvedChoices : [];
        }
        return $choiceConstraint->choices ?? [];
    }
}
