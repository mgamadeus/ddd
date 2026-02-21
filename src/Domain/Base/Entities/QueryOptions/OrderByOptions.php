<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * @property OrderByOption[] $elements
 * @method OrderByOption[] getElements()
 * @method OrderByOption first()
 * @method OrderByOption getByUniqueKey(string $uniqueKey)
 */
class OrderByOptions extends ObjectSet
{
    public static function fromString(string $orderByQuery): ?OrderByOptions
    {
        if (empty($orderByQuery)) {
            return null;
        }
        $result = preg_match_all(
            '/(?:(?:\s*,\s*)?(?<property>[a-z]+)(\s+?<direction>asc|desc)?)+?/si',
            $orderByQuery,
            $matches
        );
        if (!$result) {
            throw new BadRequestException('OrderBy definitions are syntactical incorrect');
        }
        $orderByOptions = new OrderByOptions();
        $orderByStatements = explode(',', $orderByQuery);
        foreach ($orderByStatements as $orderByStatement) {
            $orderByStatement = trim($orderByStatement);
            $orderByStatementElements = explode(' ', $orderByStatement);
            $orderByProperty = $orderByStatementElements[0];
            $orderByDirection = $orderByStatementElements[1] ?? OrderByOption::ASC;
            $orderByOption = new OrderByOption($orderByProperty, $orderByDirection);
            $orderByOptions->add($orderByOption);
        }
        return $orderByOptions;
    }

    /**
     * Returns OpenApi schmea definition regex
     * @return string
     */
    public static function getRegexForOpenApi(): string
    {
        return '^(?:(?:\s*,\s*)?(?<property>[a-z]+)(\s+?<direction>asc|desc)?)+?$';
    }

    public function getOrderByOptionByName(string $orderByOptionName): ?OrderByOption
    {
        foreach ($this->getElements() as $orderByOption) {
            if ($orderByOption->propertyName == $orderByOptionName) {
                return $orderByOption;
            }
        }
        return null;
    }

    /**
     * Validates OrderBy options against order by definitions and throws error if invalid property names are used in orderBy
     * returns true if validation finds no issues
     * @param array $orderByDefinitions
     * @return bool
     * @throws BadRequestException
     */
    public function validateAgainstDefinitions(?array $orderByDefinitions, ?ExpandOptions $expandOptions = null): bool
    {
        $orderByDefinitions = $orderByDefinitions ?? [];
        foreach ($this->getElements() as $orderByOption) {
            // First check if we have a nested orderby, e.g. account.created DESC ...

            if (($strRightPos = strrpos($orderByOption->propertyName, '.')) !== false) {
                // if propertyName is e.g. team.world.id we extract team.world and obtain team.world
                // we have to search for team.world
                $expandPath = substr($orderByOption->propertyName, 0, $strRightPos);
                $propertyNameInExpandPath = substr($orderByOption->propertyName, $strRightPos + 1);
                $expandOptionForPropertyName = null;
                if ($expandOptions) {
                    $expandOptionForPropertyName = $expandOptions->getExpandOptionByPropertyName($expandPath, recursive: true);
                }
                if (!$expandOptionForPropertyName) {
                    throw new BadRequestException(
                        "Property name used to orderBy ({$orderByOption->propertyName}), cannot be found scanning the expand path '{$expandPath}', check if you expanded necesary properties."
                    );
                }
                $orderByDefinitionsForPropertyName = $expandOptionForPropertyName->expandDefinition->getOrderbyDefinitions();
                $optionExists = in_array($propertyNameInExpandPath, $orderByDefinitionsForPropertyName);

                // Allow score suffix for fulltext ordering (e.g. nameScore) if base property exists.
                if (!$optionExists && str_ends_with($propertyNameInExpandPath, 'Score')) {
                    $basePropertyName = substr($propertyNameInExpandPath, 0, -strlen('Score'));
                    $optionExists = in_array($basePropertyName, $orderByDefinitionsForPropertyName);
                }
                if (!$optionExists) {
                    throw new BadRequestException(
                        "OrderBy option used with expand path '{$expandPath}' does not allow for property '{$propertyNameInExpandPath}'. Allowed property names under this path are: [" . implode(
                            ', ',
                            $orderByDefinitionsForPropertyName
                        ) . ']'
                    );
                }
                $orderByOption->setExpandOption($expandOptionForPropertyName);
                continue;
            }

            // Allow score suffix for fulltext ordering (e.g. nameScore) if base property exists.
            if (!in_array($orderByOption->propertyName, $orderByDefinitions) && str_ends_with($orderByOption->propertyName, 'Score')) {
                $basePropertyName = substr($orderByOption->propertyName, 0, -strlen('Score'));
                if (in_array($basePropertyName, $orderByDefinitions)) {
                    continue;
                }
            }
            if (!in_array($orderByOption->propertyName, $orderByDefinitions)) {
                throw new BadRequestException(
                    "Property name used to orderBy '{$orderByOption->propertyName}' is not allowed. Allowed property names are: [" . implode(
                        ', ',
                        $orderByDefinitions
                    ) . ']'
                );
            }
        }
        return true;
    }

    public function uniqueKey(): string
    {
        $key = md5(json_encode($this->toObject(true, true)));
        return self::uniqueKeyStatic($key);
    }

    /**
     * Applies orderBy options to query builder
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $baseAlias
     * @return DoctrineQueryBuilder
     */
    public function applyOrderByToDoctrineQueryBuilder(
        DoctrineQueryBuilder &$queryBuilder,
        string $baseModelClass = '',
        callable $mappingFunction = null
    ): DoctrineQueryBuilder {
        foreach ($this->getElements() as $orderByOption) {
            // if orderBy is based on a filter property that comes from an expand property, alias has to be empty as otherwise the base alias would be
            // added to the query, e.g. filter is 'expandProperty.name' => then no alias is needed
            $propertyName = $orderByOption->propertyName;

            // Fulltext score ordering (e.g. orderBy=nameScore DESC)
            if (str_ends_with($propertyName, 'Score')) {
                // Score alias must be a valid DQL identifier (no dots)
                $scoreAlias = str_replace('.', '__', $propertyName);

                // For nested paths like business.nameScore, base property is business.name
                $basePropertyName = substr($propertyName, 0, -strlen('Score'));
                $fulltextSearchInfo = FiltersOptions::getFulltextSearchForProperty($basePropertyName);
                if (!$fulltextSearchInfo) {
                    throw new BadRequestException(
                        "OrderBy score option '{$propertyName}' requires a corresponding fulltext filter on '{$basePropertyName}' (operator ft or fb)."
                    );
                }

                $booleanModeClause = $fulltextSearchInfo['booleanMode'] ? ' IN BOOLEAN MODE' : '';
                $parameterCount = $queryBuilder->getParameters()->count() + 1;
                $queryBuilder->setParameter($parameterCount, $fulltextSearchInfo['searchTerms']);

                $queryBuilder->addSelect(
                    "MATCH({$fulltextSearchInfo['qualifiedColumn']}) AGAINST (?{$parameterCount}{$booleanModeClause}) AS HIDDEN {$scoreAlias}"
                );
                $queryBuilder->addOrderBy($scoreAlias, $orderByOption->direction);
                continue;
            }

            if ($mappingFunction) {
                /** @var QueryOptionsPropertyMapping $queryOptionPropertyMapping */
                $queryOptionPropertyMapping = $mappingFunction($propertyName);
                $propertyName = $queryOptionPropertyMapping->propertyName;
            }
            $orderByExpression = null;
            if (($strRightPos = strrpos($propertyName, '.')) !== false) {
                $propertyNameInExpandPath = substr($orderByOption->propertyName, $strRightPos + 1);
                if ($joinAlias = $orderByOption?->getExpandOption()?->joinAlias ?? null) {
                    // validate expression
                    /** @var DoctrineModel $verifyModelClass */
                    $verifyModelClass = $orderByOption->getExpandOption()->getTargetPropertyModelClass();
                    if ($verifyModelClass === null) {
                        continue;
                    }
                    $verifyModelAlias = $verifyModelClass::MODEL_ALIAS;
                    if (!$verifyModelClass::isValidDatabaseExpression("$verifyModelAlias.$propertyNameInExpandPath")) {
                        continue;
                    }
                    $orderByExpression = "{$joinAlias}.{$propertyNameInExpandPath}";
                } else {
                    continue;
                }
            } else {
                $baseAlias = $baseModelClass::MODEL_ALIAS;
                $baseAlias = $orderByOption?->getFiltersDefinition()?->getExpandDefinition() ? '' : $baseAlias;
                $orderByExpression = ($baseAlias ? $baseAlias . '.' : '') . $propertyName;
                /** @var DoctrineModel $baseModelClass */
                if (!$baseModelClass::isValidDatabaseExpression($orderByExpression, $baseModelClass)) {
                    continue;
                }
            }
            $queryBuilder->addOrderBy($orderByExpression, $orderByOption->direction);
        }
        return $queryBuilder;
    }

}
