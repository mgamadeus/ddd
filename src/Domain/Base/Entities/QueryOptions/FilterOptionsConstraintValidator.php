<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use libphonenumber\NumberParseException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Throwable;

/**
 * Phone number validator.
 */
class FilterOptionsConstraintValidator extends ConstraintValidator
{

    /**
     * @throws NumberParseException
     */
    public function validate($filterOptions, Constraint $constraint): void
    {
        if (!$constraint instanceof FilterOptionsConstraint) {
            throw new UnexpectedTypeException($constraint, FilterOptionsConstraint::class);
        }
        if (!($filterOptions ?? null)) {
            return;
        }
        if (!$filterOptions instanceof FiltersOptions) {
            return;
        }
        try {
            if ($filterOptions->type == FiltersOptions::TYPE_EXPRESSION) {
                $reflectionClass = ReflectionClass::instance(FiltersOptions::class);
                $valueReflectionProperty = $reflectionClass->getProperty('value');
                if (!$valueReflectionProperty->isInitialized($filterOptions)) {
                    throw new BadRequestException(
                        'QueryOptions: if type is EXPRESSION value has to be initialied'
                    );
                } elseif (!(isset($filterOptions->property) && $filterOptions->property)) {
                    throw new BadRequestException(
                        'QueryOptions: if type is EXPRESSION property has to be initialied and set'
                    );
                } elseif (!isset($filterOptions->operator)) {
                    throw new BadRequestException(
                        'QueryOptions: if type is EXPRESSION operation has to be initialied'
                    );
                }
                if (($filterOptions->value === null || (!is_array($filterOptions->value) && (is_string($filterOptions->value) && strtolower($filterOptions->value) === 'null'))) && !in_array(
                        $filterOptions->operator,
                        FiltersOptions::ALLOWED_OPERATORS_ON_NULL_VALUE
                    )) {
                    throw new BadRequestException(
                        'QueryOptions: if value is null, supported operators are: ' . implode(
                            ', ',
                            FiltersOptions::ALLOWED_OPERATORS_ON_NULL_VALUE
                        )
                    );
                }
                if (is_array($filterOptions->value) && !in_array(
                        $filterOptions->operator,
                        FiltersOptions::ALLOWED_OPERATORS_ON_ARRAY_VALUE
                    )) {
                    throw new BadRequestException(
                        'QueryOptions: if value is array, the only supported operators are: ' . implode(
                            ', ',
                            FiltersOptions::ALLOWED_OPERATORS_ON_ARRAY_VALUE
                        )
                    );
                }
                if (is_array(
                        $filterOptions->value
                    ) && $filterOptions->operator == FiltersOptions::OPERATOR_BETWEEN && count(
                        $filterOptions->value
                    ) != 2) {
                    throw new BadRequestException(
                        'QueryOptions: if operator is bw (between), value has to be array formatted and needs to contain exactly 2 arguments, the array contains: ' . count(
                            $filterOptions->value
                        ) . ' arguments.'
                    );
                }
                if (!is_array($filterOptions->value) and in_array(
                        $filterOptions->operator,
                        FiltersOptions::ALLOWED_OPERATORS_ON_ARRAY_VALUE
                    )) {
                    throw new BadRequestException(
                        'QueryOptions: if operator is bw (between) or in, value has to be array formatted'
                    );
                }
            }
        } catch (Throwable $t) {
            $this->context->buildViolation($t->getMessage())->addViolation();
        }
    }
}