<?php

declare (strict_types=1);

namespace DDD\Infrastructure\Validation\Constraints;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

use function is_callable;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Choice extends \Symfony\Component\Validator\Constraints\Choice
{
    use BaseAttributeTrait;

    public function __construct(
        array|string $options = [],
        array $choices = null,
        callable|string $callback = null,
        bool $multiple = null,
        bool $strict = null,
        int $min = null,
        int $max = null,
        string $message = null,
        string $multipleMessage = null,
        string $minMessage = null,
        string $maxMessage = null,
        array $groups = null,
        mixed $payload = null
    ) {
        if ($callback) {
            if (is_callable($callback)) {
                $options = $callback();
            }
        }
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


}