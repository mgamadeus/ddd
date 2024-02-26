<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Validation;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @method ValidationResult first()
 * @method ValidationResult getByUniqueKey(string $uniqueKey)
 * @method ValidationResult[] getElements()
 * @property ValidationResult[] $elements;
 */
class ValidationError extends ObjectSet
{
    /** @var string Complete object path to the property where validation failed */
    public string $jsonPath;

    public function __construct(string $jsonPath)
    {
        $this->jsonPath = $jsonPath;
        parent::__construct();
    }
}