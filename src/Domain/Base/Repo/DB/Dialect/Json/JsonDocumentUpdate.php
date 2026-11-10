<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Json;

/**
 * Builds ONE SQL expression that rewrites a JSON column atomically, for `UPDATE … SET column = <expression>`:
 * `$dialect->jsonUpdate('`workingState`')->set($path, ':value', JsonValueKind::TEXT)->remove($otherPath)->toSql()`.
 * Operations apply in the order they are added; a NULL column counts as `{}`.
 *
 * Same on both engines: `set()` creates the last key of its path, not missing parents (use
 * {@see setObjectUnlessPresent()} for a parent first); removing a missing path changes nothing. On PostgreSQL the
 * document passes through jsonb once per statement, so key order and duplicate keys of the stored text are not kept.
 */
abstract class JsonDocumentUpdate
{
    /** @var string The SQL of the stored document, a NULL column read as an empty object */
    protected string $storedDocumentSql;

    /** @var string The SQL of the document after the operations added so far */
    protected string $documentSql;

    /**
     * Sets the value at a path; the last key is created when missing, a missing parent leaves the document unchanged.
     *
     * @param JsonPath $path
     * @param string $valueSql SQL expression of the value (a placeholder, a column, an expression)
     * @param JsonValueKind $valueKind What the expression is; SQL NULL is written as JSON null (false for BOOLEAN)
     * @return static
     */
    abstract public function set(JsonPath $path, string $valueSql, JsonValueKind $valueKind): static;

    /**
     * Makes the value at a path an object: kept when the STORED document has an object there, else set to `{}`. Call
     * it before the `set()` calls beneath that path.
     *
     * @param JsonPath $path
     * @return static
     */
    abstract public function setObjectUnlessPresent(JsonPath $path): static;

    /**
     * Removes the key at a path; a missing path changes nothing.
     *
     * @param JsonPath $path
     * @return static
     */
    abstract public function remove(JsonPath $path): static;

    /**
     * Merges a JSON object into the document (RFC 7396: a null in the patch removes the key, objects merge
     * recursively, everything else replaces).
     *
     * @param string $patchSql SQL expression of the patch as JSON text
     * @return static
     */
    abstract public function mergePatch(string $patchSql): static;

    /** The SQL expression of the rewritten document, to assign to the column. */
    abstract public function toSql(): string;
}
