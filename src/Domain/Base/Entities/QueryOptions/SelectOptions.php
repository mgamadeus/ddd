<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * @property SelectOption[] $elements
 * @method SelectOption[] getElements()
 * @method SelectOption first()
 * @method SelectOption getByUniqueKey(string $uniqueKey)
 */
class SelectOptions extends ObjectSet
{
    public static function fromString(string $selectQuery): ?SelectOptions
    {
        if (empty($selectQuery)) {
            return null;
        }
        // Regex now only extracts property names (no direction)
        $result = preg_match_all(
            '/(?:(?:\s*,\s*)?(?<property>[a-z]+))+?/si',
            $selectQuery,
            $matches
        );
        if (!$result) {
            throw new BadRequestException('Select definitions are syntactically incorrect');
        }
        $selectOptions = new SelectOptions();
        $selectStatements = explode(',', $selectQuery);
        foreach ($selectStatements as $selectStatement) {
            $selectStatement = trim($selectStatement);
            // For select, only property names matter
            $propertyName = $selectStatement;
            $selectOption = new SelectOption($propertyName);
            $selectOptions->add($selectOption);
        }
        return $selectOptions;
    }

    public function getSelectOptionByName(string $selectOptionName): ?SelectOption
    {
        foreach ($this->getElements() as $selectOption) {
            if ($selectOption->propertyName === $selectOptionName) {
                return $selectOption;
            }
        }
        return null;
    }

    /**
     * Applies select options to the Doctrine query builder.
     *
     * @param DoctrineQueryBuilder $queryBuilder
     * @param string $baseModelClass
     * @param callable|null $mappingFunction
     * @return DoctrineQueryBuilder
     */
    public function applySelectToDoctrineQueryBuilder(
        DoctrineQueryBuilder &$queryBuilder,
        string $baseModelClass = '',
        callable $mappingFunction = null
    ): DoctrineQueryBuilder {
        $alias = $baseModelClass::MODEL_ALIAS;
        $selectParts = $queryBuilder->getDQLPart('select');

        // Remove main alias selections from existing select parts
        if ($selectParts) {
            $filteredSelects = [];
            foreach ($selectParts as $exprSelect) {
                if ($exprSelect instanceof \Doctrine\ORM\Query\Expr\Select) {
                    $parts = $exprSelect->getParts();
                    $newParts = [];
                    foreach ($parts as $p) {
                        // Remove parts that exactly equal the main alias (e.g. "Track")
                        if ($p === $alias) {
                            continue;
                        }
                        $newParts[] = $p;
                    }
                    if (!empty($newParts)) {
                        $filteredSelects[] = new \Doctrine\ORM\Query\Expr\Select($newParts);
                    }
                } else {
                    // For non-Select objects, if it's exactly the alias, skip it.
                    if (is_string($exprSelect) && $exprSelect === $alias) {
                        continue;
                    }
                    $filteredSelects[] = $exprSelect;
                }
            }
            $queryBuilder->resetDQLPart('select');
            foreach ($filteredSelects as $fs) {
                $queryBuilder->add('select', $fs);
            }
        }

        // Build an array of fields to select for the main entity from SelectOptions
        $selectFields = [];
        foreach ($this->getElements() as $selectOption) {
            $propertyName = $selectOption->propertyName;
            if ($mappingFunction) {
                $mapping = $mappingFunction($propertyName);
                $propertyName = $mapping->propertyName;
            }
            // Build the fully qualified expression to validate it
            $selectExpression = ($alias ? $alias . '.' : '') . $propertyName;
            if ($baseModelClass::isValidDatabaseExpression($selectExpression, $baseModelClass)) {
                // In the partial clause, we only need the field name.
                $selectFields[] = $propertyName;
            }
        }

        if (!empty($selectFields)) {
            // Ensure the identifier is present (e.g. "id")
            if (!in_array('id', $selectFields, true)) {
                $selectFields[] = 'id';
            }
            // Build a partial select clause for the main entity.
            $partialClause = sprintf('partial %s.{%s}', $alias, implode(', ', $selectFields));
            $queryBuilder->addSelect($partialClause);
        }

        return $queryBuilder;
    }

    public function uniqueKey(): string
    {
        $key = md5(json_encode($this->toObject(true, true)));
        return self::uniqueKeyStatic($key);
    }

    /**
     * Returns OpenApi schema definition regex.
     *
     * @return string
     */
    public static function getRegexForOpenApi(): string
    {
        return '^(?:(?:\s*,\s*)?(?<property>[a-z]+))+$';
    }
}