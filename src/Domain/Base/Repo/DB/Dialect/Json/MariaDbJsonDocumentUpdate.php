<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Json;

/** MariaDB side of {@see JsonDocumentUpdate}: `JSON_SET` / `JSON_REMOVE` / `JSON_MERGE_PATCH` around the column. */
class MariaDbJsonDocumentUpdate extends JsonDocumentUpdate
{
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
        $this->documentSql = "JSON_SET({$this->documentSql}, {$path->toMariaDbPathSql()}, $jsonValueSql)";
        return $this;
    }

    public function setObjectUnlessPresent(JsonPath $path): static
    {
        $pathSql = $path->toMariaDbPathSql();
        $storedValueSql = "JSON_EXTRACT({$this->storedDocumentSql}, $pathSql)";
        $this->documentSql = "JSON_SET({$this->documentSql}, $pathSql, "
            . "IF(JSON_TYPE($storedValueSql) = 'OBJECT', $storedValueSql, JSON_OBJECT()))";
        return $this;
    }

    public function remove(JsonPath $path): static
    {
        $this->documentSql = "JSON_REMOVE({$this->documentSql}, {$path->toMariaDbPathSql()})";
        return $this;
    }

    public function mergePatch(string $patchSql): static
    {
        $this->documentSql = "JSON_MERGE_PATCH({$this->documentSql}, $patchSql)";
        return $this;
    }

    public function toSql(): string
    {
        return $this->documentSql;
    }
}
