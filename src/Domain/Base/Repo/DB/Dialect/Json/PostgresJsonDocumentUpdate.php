<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Json;

/**
 * PostgreSQL side of {@see JsonDocumentUpdate}: the json column is cast to jsonb once, rewritten with `jsonb_set` /
 * `#-` / `ddd_json_merge_patch`, and cast back to json.
 */
class PostgresJsonDocumentUpdate extends JsonDocumentUpdate
{
    public function __construct(string $columnSql)
    {
        $this->storedDocumentSql = "COALESCE(CAST($columnSql AS jsonb), '{}'::jsonb)";
        $this->documentSql = $this->storedDocumentSql;
    }

    public function set(JsonPath $path, string $valueSql, JsonValueKind $valueKind): static
    {
        $jsonValueSql = match ($valueKind) {
            JsonValueKind::TEXT => "COALESCE(to_jsonb(CAST($valueSql AS text)), 'null'::jsonb)",
            JsonValueKind::NUMBER => "COALESCE(to_jsonb(CAST($valueSql AS numeric)), 'null'::jsonb)",
            JsonValueKind::BOOLEAN => "to_jsonb(COALESCE(CAST($valueSql AS boolean), FALSE))",
            JsonValueKind::JSON => "COALESCE(CAST($valueSql AS jsonb), 'null'::jsonb)",
        };
        $this->documentSql = "jsonb_set({$this->documentSql}, {$path->toPostgresPathSql()}, $jsonValueSql, TRUE)";
        return $this;
    }

    public function setObjectUnlessPresent(JsonPath $path): static
    {
        $pathSql = $path->toPostgresPathSql();
        $storedValueSql = "({$this->storedDocumentSql} #> $pathSql)";
        $this->documentSql = "jsonb_set({$this->documentSql}, $pathSql, "
            . "CASE WHEN jsonb_typeof($storedValueSql) = 'object' THEN $storedValueSql ELSE '{}'::jsonb END, TRUE)";
        return $this;
    }

    public function remove(JsonPath $path): static
    {
        $this->documentSql = "({$this->documentSql} #- {$path->toPostgresPathSql()})";
        return $this;
    }

    public function mergePatch(string $patchSql): static
    {
        $this->documentSql = "CAST(ddd_json_merge_patch(CAST({$this->documentSql} AS json), CAST($patchSql AS json)) AS jsonb)";
        return $this;
    }

    public function toSql(): string
    {
        return "CAST({$this->documentSql} AS json)";
    }
}
