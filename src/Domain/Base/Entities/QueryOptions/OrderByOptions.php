<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Base\Repo\DB\Database\FullTextMode;
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
            '/(?:(?:\s*,\s*)?(?<property>[a-z]+)(\s+?<direction>asc|desc)?)+?/i',
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

    /**
     * The generic, endpoint-INDEPENDENT `orderBy` grammar (OData-inspired). Single source of truth, emitted ONCE per
     * surface; the per-endpoint sortable-property list is appended separately by the documenter. See {@see QueryOptionsSyntax}.
     */
    public static function getSyntaxDocumentation(): string
    {
        return <<<'MD'
### orderBy

OrderBy Options (OData-inspired)

Comma-separated list of sort keys:
`<propertyPath> <direction>?, <propertyPath> <direction>? ...`

Rules / restrictions:
- `<propertyPath>` is **not quoted** and supports dot-notation.
- Sortable fields are **endpoint-specific** and validated against the endpoint QueryOptions definitions.
- Sorting by expanded relations is supported via `<relation>.<field>` if that relation is included via `expand`.
- `<direction>` is optional (defaults to `asc`), allowed: `asc`, `desc` (case-insensitive).
- Fulltext relevance score ordering is supported via the `{propertyName}Score` suffix (e.g. `nameScore desc`).
  This requires a corresponding fulltext filter on the base property using `ft` or `fb`.
- Score ordering also works on expanded relations using dot-notation (e.g. `business.nameScore desc`),
  but requires:
  - the relation to be included via `expand=business`
  - a fulltext filter on the matching base property (e.g. `filters=business.name ft 'kfc arad'`)

Examples:
- `someField asc, otherField desc`
- `someRelation.someField desc`
- `nameScore desc`
- `business.nameScore desc`
MD;
    }

    /**
     * One-line summary used as the `orderBy` parameter description (the per-endpoint sortable list is appended after it).
     * Full grammar + examples live ONCE in {@see self::getSyntaxDocumentation()}.
     */
    public static function getParameterSummary(): string
    {
        return 'OrderBy Options (OData-inspired). Syntax: see "QueryOptions syntax".';
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
                        "Property name used to orderBy ($orderByOption->propertyName), cannot be found scanning the expand path '$expandPath', check if you expanded necesary properties."
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
                        "OrderBy option used with expand path '$expandPath' does not allow for property '$propertyNameInExpandPath'. Allowed property names under this path are: [" . implode(
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
                    "Property name used to orderBy '$orderByOption->propertyName' is not allowed. Allowed property names are: [" . implode(
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
     * The column an orderBy on $propertyName sorts: the virtual search column for a
     * #[Translatable(fullTextIndex: true)] property (its folded text, in that column's collation), the property's own
     * column otherwise — a plain Translatable keeps sorting its JSON text as before.
     *
     * @param string $modelClass The Doctrine model the property belongs to
     * @param string $propertyName The orderBy property
     * @throws ReflectionException
     */
    protected static function getOrderByColumn(string $modelClass, string $propertyName): OrderByColumn
    {
        /** @var DoctrineModel $modelClass */
        $searchColumnName = FiltersOptions::getTranslatableSearchColumnName($modelClass::ENTITY_CLASS, $propertyName);
        if ($searchColumnName && $modelClass::isValidDatabaseExpression($modelClass::MODEL_ALIAS . ".$searchColumnName")) {
            return new OrderByColumn($searchColumnName, true);
        }
        return new OrderByColumn($propertyName, false);
    }

    /**
     * The property's {@see FiltersDefinition::$columnKind}, read the same way
     * {@see FiltersDefinitions::getFiltersDefinitionsForReferenceClass()} already builds it for filtering — cached
     * per entity class there, so this is a cheap lookup.
     */
    protected static function getColumnKind(string $modelClass, string $propertyName): ?string
    {
        /** @var DoctrineModel $modelClass */
        $filtersDefinitions = FiltersDefinitions::getFiltersDefinitionsForReferenceClass($modelClass::ENTITY_CLASS);
        foreach ($filtersDefinitions?->getElements() ?? [] as $filtersDefinition) {
            if ($filtersDefinition->propertyName === $propertyName) {
                return $filtersDefinition->columnKind;
            }
        }
        return null;
    }

    /**
     * D8/Mig §3.2 (real failure of a consuming app on PostgreSQL: `could not identify an ordering operator for type json`):
     * a `json` column — including a plain (non-searchable) Translatable, stored as `json` — has no B-tree
     * opclass, so `ORDER BY` needs `JSON_TEXT()` on PostgreSQL (MariaDB sorts the stored JSON text's bytes
     * directly, `utf8mb4_bin`). Never applied when `$routedToSearchColumn` is true — the virtual search column is
     * already plain text on both engines.
     */
    protected static function wrapOrderByExpressionForPostgresJson(
        DoctrineQueryBuilder $queryBuilder,
        string $orderByExpression,
        string $modelClass,
        string $propertyName,
        bool $routedToSearchColumn
    ): string {
        if ($routedToSearchColumn || !FiltersOptions::isPostgresDialect($queryBuilder)) {
            return $orderByExpression;
        }
        $columnKind = self::getColumnKind($modelClass, $propertyName);

        return match ($columnKind) {
            FiltersDefinition::COLUMN_KIND_JSON, FiltersDefinition::COLUMN_KIND_TRANSLATABLE => "JSON_TEXT($orderByExpression)",
            default => $orderByExpression,
        };
    }

    /**
     * Applies orderBy options to query builder
     * @param DoctrineQueryBuilder $queryBuilder
     * @return DoctrineQueryBuilder
     */
    public function applyOrderByToDoctrineQueryBuilder(
        DoctrineQueryBuilder &$queryBuilder,
        string $baseModelClass = '',
        ?callable $mappingFunction = null
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
                        "OrderBy score option '$propertyName' requires a corresponding fulltext filter on '$basePropertyName' (operator ft or fb)."
                    );
                }

                $parameterCount = $queryBuilder->getParameters()->count() + 1;
                $queryBuilder->setParameter($parameterCount, $fulltextSearchInfo['searchTerms']);

                if (FiltersOptions::isPostgresDialect($queryBuilder)) {
                    /** @var FullTextMode $fullTextMode */
                    $fullTextMode = $fulltextSearchInfo['fullTextMode'] ?? FullTextMode::NAME;
                    $scoreSelect = "FULLTEXT_SCORE({$fulltextSearchInfo['qualifiedColumn']}, ?$parameterCount, '{$fullTextMode->value}')";
                } else {
                    $booleanModeClause = $fulltextSearchInfo['booleanMode'] ? ' IN BOOLEAN MODE' : '';
                    $scoreSelect = "MATCH({$fulltextSearchInfo['qualifiedColumn']}) AGAINST (?$parameterCount$booleanModeClause)";
                }

                $queryBuilder->addSelect("$scoreSelect AS HIDDEN $scoreAlias");
                $queryBuilder->addOrderBy($scoreAlias, $orderByOption->direction);
                continue;
            }

            if ($mappingFunction) {
                /** @var QueryOptionsPropertyMapping $queryOptionPropertyMapping */
                $queryOptionPropertyMapping = $mappingFunction($propertyName);
                $propertyName = $queryOptionPropertyMapping->propertyName;
            }
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
                    $orderByColumn = self::getOrderByColumn($verifyModelClass, $propertyNameInExpandPath);
                    $propertyNameInExpandPath = $orderByColumn->columnName;
                    $routedToSearchColumn = $orderByColumn->routedToSearchColumn;
                    if (!$verifyModelClass::isValidDatabaseExpression("$verifyModelAlias.$propertyNameInExpandPath")) {
                        continue;
                    }
                    $orderByExpression = "$joinAlias.$propertyNameInExpandPath";
                    $orderByExpression = self::wrapOrderByExpressionForPostgresJson(
                        $queryBuilder, $orderByExpression, $verifyModelClass, $propertyNameInExpandPath, $routedToSearchColumn
                    );
                } else {
                    continue;
                }
            } else {
                $baseAlias = $baseModelClass::MODEL_ALIAS;
                $baseAlias = $orderByOption?->getFiltersDefinition()?->getExpandDefinition() ? '' : $baseAlias;
                $routedToSearchColumn = false;
                if ($baseAlias) {
                    $orderByColumn = self::getOrderByColumn($baseModelClass, $propertyName);
                    $propertyName = $orderByColumn->columnName;
                    $routedToSearchColumn = $orderByColumn->routedToSearchColumn;
                }
                $orderByExpression = ($baseAlias ? $baseAlias . '.' : '') . $propertyName;
                /** @var DoctrineModel $baseModelClass */
                if (!$baseModelClass::isValidDatabaseExpression($orderByExpression, $baseModelClass)) {
                    continue;
                }
                if ($baseAlias) {
                    $orderByExpression = self::wrapOrderByExpressionForPostgresJson(
                        $queryBuilder, $orderByExpression, $baseModelClass, $propertyName, $routedToSearchColumn
                    );
                }
            }
            $queryBuilder->addOrderBy($orderByExpression, $orderByOption->direction);
        }
        return $queryBuilder;
    }

}
