<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Validation;

use DDD\Domain\Base\Entities\ValueObject;
use JsonException;

class ValidationResult extends ValueObject
{
    /** @var string The name of the property where the validation failed */
    public string $propertyName;

    /** @var string The error message for the validation */
    public string $errorMessage;

    /** @var string The value that was given and which failed the validation */
    public string $receivedValue;

    /** @var string Complete path to the type of the property for which the validation failed */
    public string $className;

    /**
     * @throws JsonException
     */
    public function __construct(string $propertyName, string $errorMessage, mixed $receivedValue, string $className)
    {
        $this->errorMessage = $errorMessage;
        if (is_string($receivedValue)) {
            $this->receivedValue = $receivedValue;
        } else {
            $this->receivedValue = json_encode($receivedValue, JSON_THROW_ON_ERROR);
        }
        $this->propertyName = $propertyName;
        $this->className = $className;

        parent::__construct();
    }
}