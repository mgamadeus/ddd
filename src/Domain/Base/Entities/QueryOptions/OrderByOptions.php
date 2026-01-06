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
    public function validateAgainstDefinitions(?array $orderByDefinitions): bool
    {
        $orderByDefinitions = $orderByDefinitions ?? [];
        foreach ($this->getElements() as $orderByOption) {
            if (!in_array($orderByOption->propertyName, $orderByDefinitions)) {
                throw new BadRequestException(
                    "Property name used to orderBy ({$orderByOption->propertyName}) is not allowed. Allowed property names are: [" . implode(
                        ', ',
                        $orderByDefinitions
                    ) . ']'
                );
            }
        }
        return true;
    }

    /**
     * Returns OpenApi schmea definition regex
     * @return string
     */
    public static function getRegexForOpenApi(): string
    {
        return '^(?:(?:\s*,\s*)?(?<property>[a-z]+)(\s+?<direction>asc|desc)?)+?$';
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
        foreach ($this->getElements() as $orderBy) {
            // if orderBy is based on a filter property that comes from an expand property, alias has to be empty as otherwise the base alias would be
            // added to the query, e.g. filter is 'expandProperty.name' => then no alias is needed
            $propertyName = $orderBy->propertyName;
            if ($mappingFunction) {
                /** @var QueryOptionsPropertyMapping $queryOptionPropertyMapping */
                $queryOptionPropertyMapping = $mappingFunction($propertyName);
                $propertyName = $queryOptionPropertyMapping->propertyName;
            }
            $baseAlias = $baseModelClass::MODEL_ALIAS;
            $baseAlias = $orderBy?->getFiltersDefinition()?->getExpandDefinition() ? '' : $baseAlias;
            $orderByExpression = ($baseAlias ? $baseAlias . '.' : '') . $propertyName;
            /** @var DoctrineModel $baseModelClass */
            if ($baseModelClass::isValidDatabaseExpression($orderByExpression, $baseModelClass)) {
                $queryBuilder->addOrderBy($orderByExpression, $orderBy->direction);
            }
        }
        return $queryBuilder;
    }


}