<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Traits;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\Lazyload\LazyLoad;
use DDD\Domain\Base\Entities\ParentChildrenTrait;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Validation\CustomValidationInputs;
use DDD\Infrastructure\Validation\Metadata\LazyLoadingMetadataFactory;
use DDD\Infrastructure\Validation\ValidationBuilder;
use DDD\Infrastructure\Validation\ValidationError;
use DDD\Infrastructure\Validation\ValidationErrors;
use DDD\Infrastructure\Validation\ValidationResult;
use JsonException;
use ReflectionException;
use Symfony\Component\Validator\{ConstraintViolation,
    ConstraintViolationList,
    Validation,
    Validator\ValidatorInterface};
use Throwable;

trait ValidatorTrait
{
    /** @var bool If true, this object is going to be validated, if false, validation is omitted */
    protected $toBeValidated = true;

    /**
     * Recursively validates current object and returns ValidationErrors if errors are present, otherwise returns true
     * We disable lazyloading during validation, since otherwise without intention it is triggered during the validation process
     *
     * The depth variable is used for determining a maximum depth of recursive validation, BUT works the way, that
     * if depth is < 1 only ValueObejcts are validated, NOT Entities, this is ment to mimic the behaviour of Repository
     * Updates, which when resstricted in depth, transform ValueObjects to DB suites column values but not Entities
     *
     * This is menth
     * @param ValidationErrors|null $validationErrors
     * @param string $jsonPath
     * @param array $callPath
     * @param int $depth
     * @return bool|ValidationErrors
     * @throws JsonException
     * @throws ReflectionException
     */
    public function validate(
        ValidationErrors &$validationErrors = null,
        string $jsonPath = '.',
        array $callPath = [],
        ?int $depth = null,
        bool $customValidation = false,
        CustomValidationInputs $customValidationInputs = null
    ): bool|ValidationErrors {
        // check for recursion
        if (!$this->toBeValidated) {
            return false;
        }
        if (isset($callPath[spl_object_id($this)])) {
            return false;
        }
        // if depth limit is applied and depth is reached, we do not validate entities recurisvely
        if ($depth !== null && $depth < 1 && $this instanceof Entity) {
            return true;
        }
        $callPath[spl_object_id($this)] = true;

        if ($customValidation && !$customValidationInputs) {
            return false;
        }

        // validate object itself
        LazyLoad::$disableLazyLoadGlobally = true;
        $violations = $customValidation ?
            $this->getCustomValidationInputsViolations($customValidationInputs) :
            $this->getValidationViolations();
        if ($violations->count()) {
            if (!$validationErrors) {
                $validationErrors = new ValidationErrors();
            }
            $validationError = new ValidationError($jsonPath);
            /** @var ConstraintViolation $violation */
            foreach ($violations as $violation) {
                // We treat array elements separately below
                if (!str_starts_with($violation->getPropertyPath(), '[')) {
                    $validationResult = new ValidationResult(
                        $violation->getPropertyPath(),
                        $violation->getMessage(),
                        $violation->getInvalidValue(),
                        static::class
                    );
                    $validationError->add($validationResult);
                }
            }
            // We might not have elements in the case of object that contain only an array, like an EntitySet
            if (count($validationError->elements)) {
                $validationErrors->add($validationError);
            }
        }
        // validate recursive public properties
        //foreach ($this->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
        foreach ($this->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
            $propertyName = $reflectionProperty->getName();
            if (!$reflectionProperty->isInitialized($this)) {
                continue;
            }
            $propertyValue = $reflectionProperty->getValue($this);
            if (!isset($propertyValue)) {
                continue;
            }
            // we do not validate parents
            if (is_object($propertyValue) && is_a($propertyValue, DefaultObject::class)
                && is_a($this, DefaultObject::class)
            ) {
                /** @var ParentChildrenTrait $propertyValue */
                if ($this->hasObjectInParents($propertyValue)) {
                    continue;
                }
            }
            $depth = $depth !== null ? $depth - 1 : null;
            // treating array elements separately
            if (is_array($propertyValue)) {
                foreach ($propertyValue as $key => $arrayValue) {
                    /** @var ValidatorTrait $arrayValue */
                    if (is_object($arrayValue) && method_exists($arrayValue, 'validate')) {
                        $arrayValue->validate(
                            $validationErrors,
                            $jsonPath . $propertyName . '[' . $key . ']' . '.',
                            $callPath,
                            $depth,
                            $customValidation,
                            $customValidationInputs
                        );
                    }
                }
            }
            /** @var ValidatorTrait $propertyValue */
            if (is_object($propertyValue) && method_exists($propertyValue, 'validate')) {
                $propertyValue->validate(
                    $validationErrors,
                    $jsonPath . $propertyName . '.',
                    $callPath,
                    $depth,
                    $customValidation,
                    $customValidationInputs
                );
            }
        }
        LazyLoad::$disableLazyLoadGlobally = false;
        return $validationErrors ?? true;
    }

    private function createValidatorForAnnotation(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAnnotationMapping()->getValidator();
    }

    /**
     * @return ConstraintViolationList
     */
    private function getValidationViolations(): ConstraintViolationList
    {
        $validator = $this->createValidatorForAnnotation();
        try {
            return $validator->validate($this);
        } catch (Throwable $t) {
            // we surporess class not found errors (unfortunatelly they come as generic Errors
            if (!preg_match('/Class .* not found/i', $t->getMessage(), $output_array)) {
                throw $t;
            }
        }
        return new ConstraintViolationList();
    }

    /**
     * @throws ReflectionException
     */
    private function getCustomValidationInputsViolations(CustomValidationInputs $customValidationInputs
    ): ConstraintViolationList {
        $constraintViolationList = new ConstraintViolationList();
        foreach ($this->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isInitialized($this)) {
                continue;
            }
            $propertyValue = $property->getValue($this);
            foreach ($customValidationInputs->getElements() as $customValidationInput) {
                if (!is_object($propertyValue) && !is_array($propertyValue) &&
                    $customValidationInput->value === (string)$propertyValue) {
                    $constraintViolationList->add(
                        new ConstraintViolation(
                            message: $customValidationInput->errorMessage,
                            messageTemplate: 'Custom Validation:',
                            parameters: [],
                            root: $this,
                            propertyPath: $property->getName(),
                            invalidValue: $customValidationInput->value
                        )
                    );
                }
            }
        }

        return $constraintViolationList;
    }

    /**
     * Sets object to be validated or not
     * @param bool $toBeValidated
     * @return void
     */
    public function setToBeValidated(bool $toBeValidated)
    {
        $this->toBeValidated = $toBeValidated;
    }
}