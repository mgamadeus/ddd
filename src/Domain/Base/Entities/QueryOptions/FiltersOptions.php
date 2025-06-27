<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\MethodNotAllowedException;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\Expr\Join;
use JsonException;
use ReflectionException;

/**
 * @property FiltersOptions[] $elements;
 * @property FiltersOptions[] $children
 * @method FiltersOptions[] getElements()
 * @method FiltersOptions getByUniqueKey(string $uniqueKey)
 * @method FiltersOptions first()
 */
#[FilterOptionsConstraint]
class FiltersOptions extends ObjectSet
{
    public const TYPE_OPERATION = 'operation';
    public const TYPE_EXPRESSION = 'expression';

    public const JOIN_OPERATOR_AND = 'and';
    public const JOIN_OPERATOR_OR = 'or';

    public const JOIN_OPERATORS = [self::JOIN_OPERATOR_AND, self::JOIN_OPERATOR_OR];

    /** @var string Equal */
    public const OPERATOR_EQUAL = 'eq';

    /** @var string Greater or equal */
    public const OPERATOR_GREATER_OR_EQUAL = 'ge';

    /** @var string Not equal */
    public const OPERATOR_NOT_EQUAL = 'ne';

    /** @var string Greater than */
    public const OPERATOR_GREATER_THAN = 'gt';

    /** @var string Less than */
    public const OPERATOR_LESS_THAN = 'lt';

    /** @var string Less or equal */
    public const OPERATOR_LESS_OR_EQUAL = 'le';

    /** @var string In, e.g. in [1, 2, 3, 4, '123'] */
    public const OPERATOR_IN = 'in';

    /** @var string Between, e.g. in ['2023-01-01 00:00:00','2024-01-01 00:00:00'] */
    public const OPERATOR_BETWEEN = 'bw';

    /** @var string[] The operators allowed if the value is NULL */
    public const ALLOWED_OPERATORS_ON_NULL_VALUE = [self::OPERATOR_EQUAL, self::OPERATOR_NOT_EQUAL];

    /** @var string[] The operators allowed if the value is an array type */
    public const ALLOWED_OPERATORS_ON_ARRAY_VALUE = [self::OPERATOR_IN, self::OPERATOR_BETWEEN];

    public const OPERATORS = [
        self::OPERATOR_EQUAL,
        self::OPERATOR_GREATER_OR_EQUAL,
        self::OPERATOR_NOT_EQUAL,
        self::OPERATOR_GREATER_THAN,
        self::OPERATOR_LESS_THAN,
        self::OPERATOR_LESS_OR_EQUAL,
        self::OPERATOR_IN,
        self::OPERATOR_BETWEEN
    ];

    public const OPERATORS_TO_DOCTRINE_ALLOCATION = [
        self::OPERATOR_EQUAL => 'eq',
        self::OPERATOR_NOT_EQUAL => 'neq',
        self::OPERATOR_GREATER_THAN => 'gt',
        self::OPERATOR_GREATER_OR_EQUAL => 'gte',
        self::OPERATOR_LESS_THAN => 'lt',
        self::OPERATOR_LESS_OR_EQUAL => 'lte',
        self::OPERATOR_IN => 'in',
        self::OPERATOR_BETWEEN => 'between',
    ];

    /** @var string Can be either epression or operation, an operation holds other operations or expressions */
    #[Choice(choices: [self::TYPE_EXPRESSION, self::TYPE_OPERATION, null])]
    public string $type;

    /** @var string the join operator to connect operations, e.g. and, or */
    #[Choice(choices: [self::JOIN_OPERATOR_AND, self::JOIN_OPERATOR_OR, null])]
    public string $joinOperator;

    /** @var string The property to filter on, applies only to expression */
    public string $property;

    /** @var string The operator used in an expression */
    #[Choice(choices: [
        self::OPERATOR_EQUAL,
        self::OPERATOR_GREATER_OR_EQUAL,
        self::OPERATOR_NOT_EQUAL,
        self::OPERATOR_GREATER_THAN,
        self::OPERATOR_LESS_THAN,
        self::OPERATOR_LESS_OR_EQUAL,
        self::OPERATOR_IN,
        self::OPERATOR_BETWEEN
    ])]
    public string $operator;

    /** @var string|int|float|array|null The value to filter for, applies only to expression */
    public string|int|float|array|null $value;

    /**
     * @var string|null Base alias used for Filters on expanded properties such as
     * account.id  => property will be id, alias will be expanded alias "account"
     */
    public ?string $baseAlias;

    /** @var FiltersDefinition The definition the filter is based on */
    protected ?FiltersDefinition $filtersDefinition = null;

    /** @var ExpandOption The expandOption the filter refers to in case of expanded properties */
    protected ?ExpandOption $expandOption = null;

    /**
     * Parses FiltersOptions from string
     * @param string $filterQuery
     * @return FiltersOptions|null
     * @throws BadRequestException
     * @throws JsonException
     * @throws ReflectionException
     */
    public static function fromString(string $filterQuery): ?FiltersOptions
    {
        if (empty($filterQuery)) {
            return null;
        }
        $filtersParser = new FiltersOptionsParser($filterQuery);
        $filtersOptions = $filtersParser->parse();
        //echo $filtersOptions;die();
        $validationResults = $filtersOptions->validate();
        if ($validationResults !== true) {
            $badRequestException = new BadRequestException('Request contains invalid data');
            $badRequestException->validationErrors = $validationResults;
            throw $badRequestException;
        }

        return $filtersOptions;
    }

    /**
     * Returns OpenApi schmea definition regex
     * @return string
     */
    public static function getRegexForOpenApi(): string
    {
        $regExps = [
            '(\s+and\s+|\s+or\s+)',
            '(\s+eq\s+|\s+ne\s+|\s+gt\s+|\s+lt\s+|\s+ge\s+|\s+le\s+)',
            '([a-zA-Z\._]+)',
            "(-?\d+(?:\.\d+)?|[^\\\\]{0}\'(?:(?![^\\\\]\').)*[^\\\\]?\')",
        ];
        return '^' . join('|', $regExps) . '|\s*$';
    }

    /**
     * Recursively sets definitions to FilterOptions so earch FilterOption has access to it's corresponding definition
     * @param FiltersDefinition $filtersDefinition
     */
    public function setFiltersDefinitionsForAllFilterOptions(FiltersDefinitions $filtersDefinitions): void
    {
        if ($this->type == self::TYPE_EXPRESSION && ($this->property ?? null)) {
            $filtersDefinition = $filtersDefinitions->getFilterDefinitionForPropertyName($this->property);
            if ($filtersDefinition) {
                $this->setFiltersDefinition($filtersDefinition);
            }
        }
        foreach ($this->getElements() as $filtersOptions) {
            $filtersOptions->setFiltersDefinitionsForAllFilterOptions($filtersDefinitions);
        }
    }

    /**
     * Recursively sets ExpandOption reference to FilterOptions so earch FilterOption has access to the ExpandOption is is referring to
     * @param ExpandOption $expandOption
     */
    public function setExpandOptionForAllFilterOptions(ExpandOption $expandOption): void
    {
        if ($this->type == self::TYPE_EXPRESSION && ($this->property ?? null)) {
            $this->expandOption = $expandOption;
        } else {
            foreach ($this->getElements() as $filtersOptions) {
                $filtersOptions->setExpandOptionForAllFilterOptions($expandOption);
            }
        }
    }

    /**
     * Returns the minimum maximum defined value based on filters, e.g. if created gt 2022-01-01, returns 2022-01-01
     * @param $property
     * @param $returnMin
     * @return mixed
     */
    public function getMinOrMaxValueForProperty($property, $returnMin = true): mixed
    {
        $minOrMaxFiltersOptionsForValue = $this->getExpressionForProperty(
            $property,
            $returnMin ? [
                self::OPERATOR_GREATER_OR_EQUAL,
                self::OPERATOR_GREATER_THAN,
                self::OPERATOR_BETWEEN
            ] : [self::OPERATOR_LESS_OR_EQUAL, self::OPERATOR_LESS_THAN, self::OPERATOR_BETWEEN]
        );
        if (!$minOrMaxFiltersOptionsForValue) {
            return null;
        }
        $minOrMaxPropertyValue = null;
        if (
            in_array(
                $minOrMaxFiltersOptionsForValue->operator,
                $returnMin ? [self::OPERATOR_GREATER_OR_EQUAL, self::OPERATOR_GREATER_THAN] : [
                    self::OPERATOR_LESS_OR_EQUAL,
                    self::OPERATOR_LESS_THAN
                ]
            )
        ) {
            $minOrMaxPropertyValue = $minOrMaxFiltersOptionsForValue->value;
        } elseif (
            $minOrMaxFiltersOptionsForValue->operator == self::OPERATOR_BETWEEN && is_array(
                $minOrMaxFiltersOptionsForValue->value
            ) && $this->count($minOrMaxFiltersOptionsForValue->value) > 1
        ) {
            $minOrMaxPropertyValue = $minOrMaxFiltersOptionsForValue->value[$returnMin ? 0 : 1];
        }
        return $minOrMaxPropertyValue;
    }

    /**
     * Returns the FiltersOptions instance of type expression, that contains the property name given or null if no expression is found
     * If $operators is set, it returns the first property that has the corresponding operator, e.g. lt, gt
     * @param string $property
     * @param array|null $operators
     * @return FiltersOptions|$this|null
     */
    public function getExpressionForProperty(string $property, ?array $operators = null): ?FiltersOptions
    {
        if (!isset($this->type)) {
            return null;
        }
        if ($this->type == self::TYPE_EXPRESSION && $this->property == $property) {
            if (!$operators || (in_array($this->operator, $operators))) {
                return $this;
            }
        }

        if ($this->type == self::TYPE_OPERATION) {
            foreach ($this->getElements() as $element) {
                $result = $element->getExpressionForProperty($property, $operators);
                if ($result) {
                    return $result;
                }
            }
        }
        return null;
    }

    /**
     * Recursively determines if a propertyName can have a specific value given the current FiltersOptions
     * @param string $propertyName
     * @param mixed $value
     * @return bool
     */
    public function doesPropertyAllowValue(string $propertyName, mixed $value): bool
    {
        // If this is an expression and the property doesn't match, return true
        if ($this->type === self::TYPE_EXPRESSION && $this->property !== $propertyName) {
            return true;
        }

        // If this is an expression and the property matches, check the value directly
        if ($this->type === self::TYPE_EXPRESSION && $this->property === $propertyName) {
            return $this->isValueAllowedByOperator($this->operator, $value);
        }

        // If this is an operation, recursively check the children
        if ($this->type === self::TYPE_OPERATION) {
            $results = [];
            foreach ($this->getElements() as $filtersOptions) {
                $results[] = $filtersOptions->doesPropertyAllowValue($propertyName, $value);
            }

            // Combine results based on the join operator
            if ($this->joinOperator === self::JOIN_OPERATOR_AND) {
                return !in_array(false, $results, true);
            } elseif ($this->joinOperator === self::JOIN_OPERATOR_OR) {
                return in_array(true, $results, true);
            }
        }
        return false;
    }

    protected function isValueAllowedByOperator(string $operator, mixed $value): bool
    {
        return match ($operator) {
            self::OPERATOR_EQUAL => $value === $this->value,
            self::OPERATOR_GREATER_OR_EQUAL => is_numeric($value) && $value >= $this->value,
            self::OPERATOR_NOT_EQUAL => $value !== $this->value,
            self::OPERATOR_GREATER_THAN => is_numeric($value) && $value > $this->value,
            self::OPERATOR_LESS_THAN => is_numeric($value) && $value < $this->value,
            self::OPERATOR_LESS_OR_EQUAL => is_numeric($value) && $value <= $this->value,
            self::OPERATOR_IN => is_array($this->value) && in_array($value, $this->value),
            self::OPERATOR_BETWEEN => is_array($this->value) && count(
                    $this->value
                ) == 2 && $value >= $this->value[0] && $value <= $this->value[1],
            default => false,
        };
    }

    /**
     * Returns FiltersOptions array of instances that contain the property name given or null if none is found
     * @param string $property
     * @return $this|FiltersOptions[]|null
     */
    public function getExpressionsForProperty(string $property): ?array
    {
        if ($this->type == self::TYPE_EXPRESSION && $this->property == $property) {
            return [$this];
        }

        $filtersOptionsArray = [];
        if ($this->type === self::TYPE_OPERATION) {
            foreach ($this->getElements() as $element) {
                $filterOption = $element->getExpressionForProperty($property);
                if ($filterOption) {
                    $filtersOptionsArray[] = $filterOption;
                }
            }
        }

        if (!$filtersOptionsArray) {
            return null;
        }
        return $filtersOptionsArray;
    }

    /**
     * Validates recursively the filters against allowed property definitions and throws Error if invalid property names or options for values are used.
     * returns true if validation finds no issues.
     * ExpandOptions are are required for recursive validation
     * @param FiltersDefinitions $filtersDefinitions
     * @param ExpandOptions|null $expandOptions
     * @return bool
     * @throws BadRequestException
     */
    public function validateAgainstDefinitions(
        FiltersDefinitions $filtersDefinitions,
        ?ExpandOptions $expandOptions = null
    ): bool {
        if ($this->type == self::TYPE_OPERATION) {
            $result = true;
            foreach ($this->getElements() as $element) {
                $result = $result && $element->validateAgainstDefinitions($filtersDefinitions, $expandOptions);
            }
            return $result;
        }
        if (!($filterDefinition = $filtersDefinitions->getFilterDefinitionForPropertyName($this->property))) {
            // First check if we have a nested filter, e.g. account.email eq ...
            $filterPropertyExploded = explode('.', $this->property);
            $valid = false;
            if (count($filterPropertyExploded) > 1) {
                $firstPropertyComponent = $filterPropertyExploded[0];
                if ($expandOptions && $expandOption = $expandOptions->getExpandOptionByPropertyName($firstPropertyComponent)) {
                    if (isset($expandOption->expandDefinition)) {
                        if ($filtersDefinitionsForTargetProperty = $expandOption->expandDefinition->getFiltersDefinitions()) {
                            $remainingFiltersOptions = new self();
                            $remainingFiltersOptions->type = self::TYPE_EXPRESSION;
                            $remainingFilterPropertyExploded = array_slice($filterPropertyExploded, 1);
                            $remainingFiltersOptions->property = implode('.', $remainingFilterPropertyExploded);
                            $remainingFiltersOptions->value = $this->value;
                            $subExpandOptions = isset($expandOption->expandOptions) ? $expandOption->expandOptions : null;
                            $valid = $remainingFiltersOptions->validateAgainstDefinitions($filtersDefinitionsForTargetProperty, $subExpandOptions);
                            if (count($remainingFilterPropertyExploded) == 1) {
                                // we are on the second deepest lavel of a account.world.id => currrent level = world and remaining is id
                                // the recurive call $remainingFiltersOptions->validateAgainstDefinitions will execute in the root level of this function
                                // outside of this if statement as the remaining property has no "."
                                // in this case, we have to store the expand option
                                $this->expandOption = $expandOption;
                            } else {
                                // we are not in the deepest recursion level but also not at the bottom, as the deepest iteration
                                // will run code outside of this if and expand option as been stored on a deeper level, we need to pass it up
                                $this->expandOption = $remainingFiltersOptions->expandOption;
                            }
                            if ($valid) {
                                return true;
                            }
                        }
                    }
                }
            }
            $allowedPropertyNames = [];
            foreach ($filtersDefinitions->getElements() as $filterDefinition) {
                $allowedPropertyNames[] = $filterDefinition->propertyName;
            }
            throw new BadRequestException(
                "Property name used to filter '{$this->property}' is not allowed. Allowed property names are: [" . implode(
                    ', ',
                    $allowedPropertyNames
                ) . ']'
            );
        }
        $this->filtersDefinition = $filterDefinition;
        if ($expandDefinition = $filterDefinition->getExpandDefinition()) {
            $expandOption = $expandOptions->getExpandOptionByPropertyName($expandDefinition->propertyName);
            if ($expandDefinition && !$expandOption) {
                throw new BadRequestException(
                    "Property name used to filter '{$this->property}' references a property that has to be expanded ({$expandDefinition->propertyName}), but an expand option for '{$expandDefinition->propertyName}' is not present."
                );
            }
        }
        if (
            $filterDefinition->options && !is_array($this->value) && !in_array(
                $this->value,
                $filterDefinition->options
            )
        ) {
            throw new BadRequestException(
                "Filter applied to property name '{$this->property}' is not allowed. Allowed values are: [" . implode(
                    ', ',
                    $filterDefinition->options
                ) . ']'
            );
        } elseif ($filterDefinition->options && is_array($this->value)) {
            $arrayDiff = $diff = array_diff($this->value, $filterDefinition->options);
            if (!empty($arrayDiff)) {
                throw new BadRequestException(
                    "The following Filters applied to property name '{$this->property}' are not allowed: [" . implode(
                        ', ',
                        $arrayDiff
                    ) . ']. Allowed values are: [' . implode(
                        ', ',
                        $filterDefinition->options
                    ) . ']'
                );
            }
        }
        return true;
    }

    public function uniqueKey(): string
    {
        $key = '';
        if ($this->type == self::TYPE_EXPRESSION) {
            $key .= $this->property . '_' . $this->operator . '_' . (is_array($this->value) ? json_encode(
                    $this->value
                ) : $this->value) . '_';
        } else {
            $key = $this->joinOperator . '_' . implode(
                    ', ',
                    array_map(function (FiltersOptions $filterOptions) {
                        return $filterOptions->uniqueKey();
                    }, $this->elements)
                );
        }
        $key = md5($key);
        return self::uniqueKeyStatic($key);
    }

    /**
     * Searches for a Join matching the given join path and alias in the QueryBuilder,
     * augments its ON/WITH condition by AND’ing the filter Expression generated by
     * getFiltersExpressionForDoctrineQueryBuilder(), and replaces that Join entry in the DQL parts.
     *
     * @param DoctrineQueryBuilder $queryBuilder The QueryBuilder containing the joins.
     * @param string $joinString The join path (e.g. "push.world").
     * @param string $joinAlias The alias of the join (e.g. "world").
     * @param string $baseModelClass Fully qualified model class for filter logic.
     * @param callable|null $mappingFunction Optional callback to transform property/value.
     * @param string|null $baseModelAlias Alias of the root entity under which the join lives.
     *
     * @throws ReflectionException
     */
    public function applyFiltersToJoin(
        DoctrineQueryBuilder &$queryBuilder,
        string $rootModelAlias,
        string $joinString,
        string $joinAlias,
        ?callable $mappingFunction = null,
    ): void {
        // Build the filter expression. If it’s empty, do nothing.
        $filterExpr = $this->getFiltersExpressionForDoctrineQueryBuilder(
            $queryBuilder,
            $mappingFunction,
        );
        if ($filterExpr === null || (is_string($filterExpr) && trim($filterExpr) === '')) {
            return;
        }

        // Retrieve all existing Join objects organized by the root alias.
        $allJoins = $queryBuilder->getDQLPart('join');
        if (empty($allJoins[$rootModelAlias])) {
            return;
        }

        $joinsForAlias = $allJoins[$rootModelAlias];
        $newJoins = [];

        foreach ($joinsForAlias as $existingJoin) {
            /** @var Join $existingJoin */
            if (!($existingJoin instanceof Join)) {
                $newJoins[] = $existingJoin;
                continue;
            }

            // Identify the Join matching both path and alias.
            if (
                $existingJoin->getJoin() === $joinString && $existingJoin->getAlias() === $joinAlias
            ) {
                // Retrieve existing ON/WITH condition, which may be null, a string, or an Expr.
                $existingCondition = $existingJoin->getCondition();

                // Combine existing condition with the filter expression using AND.
                if ($existingCondition === null || (is_string($existingCondition) && trim($existingCondition) === '')) {
                    $combinedCondition = $filterExpr;
                } else {
                    $andX = new Expr\Andx();
                    $andX->add($existingCondition);
                    $andX->add($filterExpr);
                    $combinedCondition = $andX;
                }

                // Create a new Join object with the same join type, path, and alias,
                // but with the merged ON/WITH condition.
                $newJoins[] = new Join(
                    $existingJoin->getJoinType(), $existingJoin->getJoin(), $existingJoin->getAlias(), Join::WITH, $combinedCondition
                );
            } else {
                // For joins that do not match, keep them unchanged.
                $newJoins[] = $existingJoin;
            }
        }

        // Replace the join list under the same base alias with the updated array.
        $allJoins[$rootModelAlias] = $newJoins;
        $queryBuilder->resetDQLPart('join');
        // Re-add every Join under each alias using add('join', …, true).  [oai_citation:70‡stackoverflow.com](https://stackoverflow.com/questions/17471884/doctrine2-how-to-remove-leftjoin-part-of-querybuilder?utm_source=chatgpt.com) [oai_citation:71‡stackoverflow.com](https://stackoverflow.com/questions/17471884/doctrine2-how-to-remove-leftjoin-part-of-querybuilder?utm_source=chatgpt.com) [oai_citation:72‡github.com](https://github.com/doctrine/doctrine2/issues/5079?utm_source=chatgpt.com)
        foreach ($allJoins as $rootAlias => $joinsArray) {
            foreach ($joinsArray as $joinObj) {
                /** @var Join $joinObj */
                $queryBuilder->add('join', [$rootAlias => $joinObj], true);
            }
        }
    }

    /**
     * @param DoctrineQueryBuilder $queryBuilder
     * @param callable|null $mappingFunction
     * @return Expr\Orx|Expr\Andx|Expr\Comparison|Expr\Func|string|null
     *
     * @throws MethodNotAllowedException
     * @throws ReflectionException
     */
    protected function getFiltersExpressionForDoctrineQueryBuilder(
        DoctrineQueryBuilder &$queryBuilder,
        callable $mappingFunction = null,
    ): Expr\Orx|Expr\Andx|Expr\Comparison|Expr\Func|string|null {
        if ($this->type == self::TYPE_OPERATION) {
            $operator = $this->joinOperator == self::JOIN_OPERATOR_AND ? 'andX' : 'orX';

            $childExpressions = [];
            foreach ($this->getElements() as $filtersOptions) {
                // we collect recursively generated child expressions
                $childExpression = $filtersOptions->getFiltersExpressionForDoctrineQueryBuilder(
                    $queryBuilder,
                    $mappingFunction,
                );
                if ($childExpression) {
                    $childExpressions[] = $childExpression;
                }
            }
            // we compose an expression out of the child expressions
            /** @var Expr\Orx|Expr\Andx $expression */
            return $queryBuilder->expr()->$operator(...$childExpressions);
        } else {
            $operator = self::OPERATORS_TO_DOCTRINE_ALLOCATION[$this->operator];
            $value = $this->value;
            /** @var Expr $expression */
            if (is_string($this->value) && strpos($this->value, '*') !== false) {
                $value = str_replace('*', '%', $this->value);
                if ($this->operator == self::OPERATOR_EQUAL) {
                    $operator = 'like';
                } elseif ($this->operator == self::OPERATOR_NOT_EQUAL) {
                    $operator = 'notLike';
                }
            }

            $propertyName = $this->getApplicablePropertyNameConsideringExpandJoinAlias();

            if ($mappingFunction) {
                /** @var QueryOptionsPropertyMapping $queryOptionPropertyMapping */
                $queryOptionPropertyMapping = $mappingFunction($propertyName, $value);
                $propertyName = $queryOptionPropertyMapping->propertyName;
                $value = $queryOptionPropertyMapping->value;
            }

            // Validate expression
            if ($this->expandOption && $this->expandOption->joinAlias) {
                $baseModelAlias = $this->expandOption->joinAlias;
                // validate expression
                /** @var DoctrineModel $verifyModelClass */
                $verifyModelClass = $this->expandOption->getTargetPropertyModelClass();
                if ($verifyModelClass === null) {
                    return null;
                }
                $verifyModelAlias = $verifyModelClass::MODEL_ALIAS;
                if (!$verifyModelClass::isValidDatabaseExpression("$verifyModelAlias.$propertyName")) {
                    return null;
                }
            } else {
                $baseModelClass = $this->getBaseModelClass();
                if ($baseModelClass === null) {
                    return null;
                }
                $baseModelAlias = $baseModelClass::MODEL_ALIAS;
                /** @var DoctrineModel $baseModelClass */
                if (!$baseModelClass::isValidDatabaseExpression("$baseModelAlias.$propertyName")) {
                    return null;
                }
            }

            $baseModelAliasApplied = $baseModelAlias ? $baseModelAlias . '.' : '';
            if (is_string($this->value) && (strtoupper($this->value) == 'NULL' || $value === null)) {
                if ($this->operator == self::OPERATOR_EQUAL) {
                    $operator = 'isNull';
                } elseif ($this->operator == self::OPERATOR_NOT_EQUAL) {
                    $operator = 'isNotNull';
                }
                return $queryBuilder->expr()->$operator("{$baseModelAliasApplied}{$propertyName}");
            }

            $parameterCount = $queryBuilder->getParameters()->count() + 1;
            $operatorParams = ["{$baseModelAliasApplied}{$propertyName}", '?' . $parameterCount];
            $parameters = [$parameterCount => $value];
            if ($this->operator == self::OPERATOR_BETWEEN) {
                $operatorParams[] = '?' . $parameterCount + 1;
                $parameters = [$parameterCount => $value[0], ($parameterCount + 1) => $value[1]];
                // between sets 2 params, so we need to increment again
            }
            foreach ($parameters as $index => $value) {
                $queryBuilder->setParameter($index, $value);
            }
            return $queryBuilder->expr()->$operator(...$operatorParams);
        }
    }

    /**
     * Returns property name that can be applied considering the expand join alias
     * If no expand is present, returns property name
     * e.g. account.world.id => returns id
     * @return string|null
     */
    public function getApplicablePropertyNameConsideringExpandJoinAlias(): ?string
    {
        if ($this->type != self::TYPE_EXPRESSION) {
            return null;
        }
        if (!(isset($this->expandOption) && $this->expandOption && $this->expandOption->joinAlias)) {
            return $this->property;
        }
        $filterPropertyExploded = explode('.', $this->property);
        if (count($filterPropertyExploded) <= 1) {
            return $this->property;
        }
        return end($filterPropertyExploded);
    }

    /**
     * Returns Doctrine Model class for reference class
     * @return string|null
     * @throws MethodNotAllowedException
     */
    public function getBaseModelClass(): ?string
    {
        $referenceRepoClass = $this->getReferenceClassRepo();
        if ($referenceRepoClass) {
            /** @var DBEntity $referenceRepoClass */
            return $referenceRepoClass::BASE_ORM_MODEL;
        }
        return null;
    }

    /**
     * Returns reference class of the FitlersDefinition
     * @return string|null
     * @throws MethodNotAllowedException
     */
    public function getReferenceClassRepo(): ?string
    {
        return $this->filtersDefinition->getReferenceClassRepo();
    }

    public function applyFiltersToDoctrineQueryBuilder(
        DoctrineQueryBuilder &$queryBuilder,
        callable $mappingFunction = null,
    ): DoctrineQueryBuilder {
        /** @var DoctrineModel $baseModelClass */
        $expression = $this->getFiltersExpressionForDoctrineQueryBuilder(
            $queryBuilder,
            $mappingFunction,
        );
        $queryBuilder->andWhere($expression);
        return $queryBuilder;
    }

    /**
     * Returns reference class of the FiltersDefinition
     * @return string|null
     * @throws MethodNotAllowedException
     */
    public function getReferenceClass(): ?string
    {
        return $this->filtersDefinition->getReferenceClass();
    }

    /**
     * @return FiltersDefinition
     */
    public function getFiltersDefinition(): FiltersDefinition
    {
        return $this->filtersDefinition;
    }

    /**
     * @param FiltersDefinition $filtersDefinition
     */
    public function setFiltersDefinition(FiltersDefinition $filtersDefinition): void
    {
        $this->filtersDefinition = $filtersDefinition;
    }

    /**
     * Adds all Expressions from other FiltersOptions
     * @param FiltersOptions $filtersOptions
     * @param string $joinOperator
     * @return void
     * @throws ReflectionException
     */
    public function addExpressionsFromFiltersOptions(
        FiltersOptions $filtersOptions,
        string $joinOperator = self::JOIN_OPERATOR_AND
    ) {
        if (!isset($this->type)) {
            // in case this QueryOptions are virign, we set them up as standard AND operation in order
            // to add the other expressions
            $this->type = self::TYPE_OPERATION;
            $this->joinOperator = self::JOIN_OPERATOR_AND;
        }
        if ($this->type == self::TYPE_OPERATION) {
            foreach ($filtersOptions->getExpressions() as $filtersOption) {
                if ($presentOption = $this->getExpressionForProperty($filtersOption->property)) {
                    $presentOption->value = $filtersOption->value;
                    $presentOption->operator = $filtersOption->value;
                } else {
                    $this->add($filtersOption);
                }
            }
        } elseif ($this->type == self::TYPE_EXPRESSION) {
            // if we have an expression, we add is as child and clear the property and value, then we add the other filters
            $selfClone = $this->clone();
            $this->type = self::TYPE_OPERATION;
            $this->joinOperator = $joinOperator;
            $this->add($selfClone);
            unset($this->property);
            unset($this->value);
            unset($this->operator);
            $this->add($filtersOptions);
        }
    }

    /**
     * Returns flat array of all expressions, especially usefull in cases where no logical operations are possible,
     * e.g. with some external APIs
     * @return FiltersOptions[] array
     */
    public function getExpressions(): array
    {
        $return = [];
        if (!isset($this->type)) {
            return $return;
        }
        if ($this->type == self::TYPE_OPERATION) {
            foreach ($this->getElements() as $filtersOptions) {
                $return = array_merge($return, $filtersOptions->getExpressions());
            }
        } else {
            $return[] = $this;
        }
        return $return;
    }
}