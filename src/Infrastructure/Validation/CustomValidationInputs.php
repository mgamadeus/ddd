<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Validation;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @method CustomValidationInput first()
 * @method CustomValidationInput getByUniqueKey(string $uniqueKey)
 * @method CustomValidationInput[] getElements()
 * @property CustomValidationInput[] $elements;
 */
class CustomValidationInputs extends ObjectSet
{

}