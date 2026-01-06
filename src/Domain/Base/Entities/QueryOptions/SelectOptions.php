<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistory;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumn;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use Doctrine\ORM\Query\Expr\Select;
use ReflectionException;
use ReflectionProperty;

/**
 * @property SelectOption[] $elements
 * @method SelectOption[] getElements()
 * @method SelectOption first()
 * @method SelectOption getByUniqueKey(string $uniqueKey)
 */
class SelectOptions extends ObjectSet
{
    public static $propertiesToHideByJoinPath = [];

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

    /**
     * Returns OpenApi schema definition regex.
     *
     * @return string
     */
    public static function getRegexForOpenApi(): string
    {
        return '^(?:(?:\s*,\s*)?(?<property>[a-z]+))+$';
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
     * @param string|null $baseModelAlias
     * @return DoctrineQueryBuilder
     * @throws ReflectionException
     */
    public function applySelectToDoctrineQueryBuilder(
        DoctrineQueryBuilder &$queryBuilder,
        string $baseModelClass = '',
        callable $mappingFunction = null,
        ?string $baseModelAlias = null,
    ): DoctrineQueryBuilder {
        // if a baseModelAlias is provided, we are usually applying select options within expand options of of the following kind:
        // expand=worldMembers(expand=world(select=id,name))
        // these are applied in ExpandOptions->applyExpandOptionsToDoctrineQueryBuilder on the alias of the expanded entity, e.g.
        // $join = "{$modelAlias}.$expandOption->propertyName";
        // $queryBuilder->addSelect($expandOption->propertyName); // => here we need to get rid of the full entity on expansion and apply desired columns

        // Determine the alias to filter out using the provided baseModelAlias or the default MODEL_ALIAS.
        $alias = $baseModelAlias ?? $baseModelClass::MODEL_ALIAS;
        // Retrieve existing SELECT parts from the query builder
        $selectParts = $queryBuilder->getDQLPart('select');
        if ($selectParts) {
            // Initialize an array to hold filtered select expressions
            $filteredParts = [];
            // Iterate through each select expression
            foreach ($selectParts as $exprSelect) {
                // If this is a Select object, process its parts
                if ($exprSelect instanceof Select) {
                    foreach ($exprSelect->getParts() as $part) {
                        // Exclude the main alias to avoid selecting the entire entity
                        if ($part !== $alias) {
                            $filteredParts[] = $part;
                        }
                    }
                } elseif (is_string($exprSelect) && $exprSelect !== $alias) {
                    // For plain string expressions, exclude the alias and keep the rest
                    $filteredParts[] = $exprSelect;
                }
            }
            // Reset the SELECT clause to clear existing selections
            $queryBuilder->resetDQLPart('select');
            // Re-add filtered parts using addSelect(), pres
            //erving multiple selection expressions
            foreach ($filteredParts as $part) {
                $queryBuilder->addSelect($part);
            }
        }

        // Build an array of fields to select for the main entity from SelectOptions
        // These are used for partial select
        $fieldsForDBSelect = [];
        // Fields not contained here, are added as propertiesToHide, this is kept separated from the fields in DB select
        // As some fields could e.g. come from non db properties, e.g. virtual lazyload properties
        $fieldsToKeepInEntity = [];
        foreach ($this->getElements() as $selectOption) {
            $propertyName = $selectOption->propertyName;
            if ($mappingFunction) {
                $mapping = $mappingFunction($propertyName);
                $propertyName = $mapping->propertyName;
            }
            // Build the fully qualified expression to validate it
            if (!$baseModelAlias) {
                $selectExpression = ($alias ? $alias . '.' : '') . $propertyName;
            } else {
                // in case we apply select options to a join $baseModelAlias is passed and usually does not correspond to
                // the $baseModelClass::MODEL_ALIAS anymore
                // e.g. left join Worlds world, alias: 'world' vs MODEL_ALIAS: 'World'
                // so in this case we use the $baseModelClass::MODEL_ALIAS to contruct the select expression to check.
                $tAlias = $baseModelClass ? $baseModelClass::MODEL_ALIAS : '';
                $selectExpression = ($tAlias ? $tAlias . '.' : '') . $propertyName;
            }
            /** @var DoctrineModel $baseModelClass */
            if ($baseModelClass::isValidDatabaseExpression($selectExpression, $baseModelClass)) {
                // In the partial clause, we only need the field name.
                $fieldsForDBSelect[] = $propertyName;
            }
            // Virtual Columns are named in DB different that in Entity, and property name needs to be adjusted
            if (isset($baseModelClass::$virtualColumns[$propertyName])) {
                $entityPropertyName = DatabaseVirtualColumn::getColumnNameForVirtualColumn($propertyName);
                $fieldsToKeepInEntity[] = DatabaseVirtualColumn::getColumnNameForVirtualColumn($propertyName);
            }
            else {
                $fieldsToKeepInEntity[] = $propertyName;
            }
        }

        if (!empty($fieldsForDBSelect)) {
            // Ensure the identifier is present (e.g. "id")
            if (!in_array('id', $fieldsForDBSelect, true)) {
                $fieldsForDBSelect[] = 'id';
                $fieldsToKeepInEntity[] = 'id';
            }
            // Build a partial select clause for the main entity.
            $partialClause = sprintf('partial %s.{%s}', $alias, implode(', ', $fieldsForDBSelect));
            $queryBuilder->addSelect($partialClause);
            self::addPropertiesToHideByByJoinPath($baseModelClass::ENTITY_CLASS, self::extractJoinPathFromJoinAlias($alias ?? ''), $fieldsToKeepInEntity);
        }

        return $queryBuilder;
    }

    public static function addPropertiesToHideByByJoinPath(string $baseEntityClass, string $joinPath, array $selectedProperties): void
    {
        if (isset(self::$propertiesToHideByJoinPath[$joinPath])) {
            return;
        }
        $selectedPropertiesAssoc = [];
        foreach ($selectedProperties as $propertyName) {
            $selectedPropertiesAssoc[$propertyName] = true;
        }
        $baseEntityReflectionClass = ReflectionClass::instance($baseEntityClass);
        if (!$baseEntityReflectionClass) {
            return;
        }
        $publicProperties = $baseEntityReflectionClass->getProperties(ReflectionProperty::IS_PUBLIC);
        $propertiesToHide = [];
        foreach ($publicProperties as $publicProperty) {
            if ($publicProperty->getName() == 'id') {
                continue;
            }
            if (!isset($selectedPropertiesAssoc[$publicProperty->getName()])) {
                $propertiesToHide[$publicProperty->getName()] = true;
            }
        }
        // handle ChangeHistory
        if (isset($propertiesToHide['changeHistory']) && (isset($selectedProperties[ChangeHistory::DEFAULT_CREATED_COLUMN_NAME]) || isset($selectedProperties[ChangeHistory::DEFAULT_MODIFIED_COLUMN_NAME]))) {
            unset($propertiesToHide['changeHistory']);
        }
        self::$propertiesToHideByJoinPath[$joinPath] = array_keys($propertiesToHide);
    }

    /**
     * Extracts the full property path from a recursive join alias.
     * Examples:
     *   "RouteProblem_account__Account_world.partner" => "account.world.partner"
     *   "Root_foo__Foo_bar.baz__Baz_qux"             => "foo.bar.baz.qux"
     *   "RouteProblem"                               => ""
     */
    public static function extractJoinPathFromJoinAlias(string $alias): string
    {
        // If there’s no "__" and no "_" at all, it’s just the root alias
        if (strpos($alias, '__') === false && strpos($alias, '_') === false) {
            return '';
        }

        // Split recursive segments by "__"
        $segments = explode('__', $alias);
        $pathParts = [];

        foreach ($segments as $seg) {
            // Find first "_" separating model alias from the path part
            $pos = strpos($seg, '_');

            if ($pos === false) {
                // No "_" found -> skip, likely a root alias segment
                continue;
            }

            // Everything after the first "_" is the (possibly dotted) path
            $path = substr($seg, $pos + 1);

            if ($path !== '' && $path !== false) {
                // Split by "." to normalize, then append
                foreach (explode('.', $path) as $p) {
                    if ($p !== '') {
                        $pathParts[] = $p;
                    }
                }
            }
        }

        return implode('.', $pathParts);
    }

    public function uniqueKey(): string
    {
        $key = md5(json_encode($this->toObject(true, true)));
        return self::uniqueKeyStatic($key);
    }
}