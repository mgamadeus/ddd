<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Json;

/**
 * MariaDB side of {@see JsonDocumentUpdate}: `JSON_SET` / `JSON_REMOVE` / `JSON_MERGE_PATCH` around the column.
 * Consecutive `set()` / `setObjectUnlessPresent()` calls render as ONE `JSON_SET` with several path/value pairs,
 * consecutive `remove()` calls as ONE `JSON_REMOVE` with several paths — MariaDB applies the pairs left to right, so
 * the result equals the nested form while the document is parsed once per function.
 */
class MariaDbJsonDocumentUpdate extends JsonDocumentUpdate
{
    protected const string FUNCTION_JSON_SET = 'JSON_SET';

    protected const string FUNCTION_JSON_REMOVE = 'JSON_REMOVE';

    /** @var string|null The function the pending arguments belong to (`JSON_SET` / `JSON_REMOVE`), null when none */
    protected ?string $pendingFunctionName = null;

    /** @var string[] SQL arguments of the pending function after the document: path, value, path, value … or paths */
    protected array $pendingArgumentSqls = [];

    public function __construct(string $columnSql)
    {
        $this->storedDocumentSql = "COALESCE($columnSql, '{}')";
        $this->documentSql = $this->storedDocumentSql;
    }

    public function set(JsonPath $path, string $valueSql, JsonValueKind $valueKind): static
    {
        $jsonValueSql = match ($valueKind) {
            JsonValueKind::TEXT => "CAST($valueSql AS CHAR)",
            JsonValueKind::NUMBER => "($valueSql)",
            JsonValueKind::BOOLEAN => "JSON_EXTRACT(IF($valueSql, 'true', 'false'), '$')",
            JsonValueKind::JSON => "JSON_EXTRACT($valueSql, '$')",
        };
        $this->addPendingArguments(self::FUNCTION_JSON_SET, $path->toMariaDbPathSql(), $jsonValueSql);
        return $this;
    }

    public function setObjectUnlessPresent(JsonPath $path): static
    {
        $pathSql = $path->toMariaDbPathSql();
        $storedValueSql = "JSON_EXTRACT({$this->storedDocumentSql}, $pathSql)";
        $this->addPendingArguments(
            self::FUNCTION_JSON_SET,
            $pathSql,
            "IF(JSON_TYPE($storedValueSql) = 'OBJECT', $storedValueSql, JSON_OBJECT())"
        );
        return $this;
    }

    public function remove(JsonPath $path): static
    {
        $this->addPendingArguments(self::FUNCTION_JSON_REMOVE, $path->toMariaDbPathSql());
        return $this;
    }

    public function mergePatch(string $patchSql): static
    {
        $this->documentSql = "JSON_MERGE_PATCH({$this->getDocumentSqlWithPendingFunction()}, $patchSql)";
        $this->pendingFunctionName = null;
        $this->pendingArgumentSqls = [];
        return $this;
    }

    public function toSql(): string
    {
        return $this->getDocumentSqlWithPendingFunction();
    }

    /**
     * Adds arguments to the pending function; a different pending function is closed around the document first.
     *
     * @param string $functionName {@see FUNCTION_JSON_SET} or {@see FUNCTION_JSON_REMOVE}
     * @param string ...$argumentSqls
     * @return void
     */
    protected function addPendingArguments(string $functionName, string ...$argumentSqls): void
    {
        if ($this->pendingFunctionName !== $functionName) {
            $this->documentSql = $this->getDocumentSqlWithPendingFunction();
            $this->pendingFunctionName = $functionName;
            $this->pendingArgumentSqls = [];
        }
        array_push($this->pendingArgumentSqls, ...$argumentSqls);
    }

    /** The document SQL with the pending function applied; the builder itself is not changed. */
    protected function getDocumentSqlWithPendingFunction(): string
    {
        if ($this->pendingFunctionName === null) {
            return $this->documentSql;
        }
        return "{$this->pendingFunctionName}({$this->documentSql}, " . implode(', ', $this->pendingArgumentSqls) . ')';
    }
}
