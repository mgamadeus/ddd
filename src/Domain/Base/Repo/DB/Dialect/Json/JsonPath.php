<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Json;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\InternalErrorException;

/**
 * A path of object keys into a JSON document, written once for both engines: `JsonPath::fromKeys('subAgent', 'runs')
 * ->withParameter(':runNumber')->withKey('status')`. A segment is a literal key or a bound parameter that holds a
 * key (a run number, a usage scope); array indexes and wildcards are not supported. The empty path is the document
 * itself.
 */
class JsonPath extends ValueObject
{
    /**
     * @param string[] $segments Literal keys and parameter placeholders, in path order
     * @param int[] $parameterSegmentIndexes Indexes of the segments that are parameter placeholders
     */
    public function __construct(
        public readonly array $segments = [],
        public readonly array $parameterSegmentIndexes = [],
    ) {
        parent::__construct();
    }

    /**
     * @param string ...$keys Literal object keys, outermost first
     * @return static
     */
    public static function fromKeys(string ...$keys): static
    {
        return new static(array_values($keys));
    }

    /**
     * @param string $key Literal object key
     * @return static This path with the key appended
     */
    public function withKey(string $key): static
    {
        return new static([...$this->segments, $key], $this->parameterSegmentIndexes);
    }

    /**
     * @param string $placeholder A parameter placeholder (`:runNumber` or `?`) whose bound value is the key
     * @return static This path with the parameter appended
     * @throws InternalErrorException When the argument is no placeholder
     */
    public function withParameter(string $placeholder): static
    {
        if ($placeholder !== '?' && !preg_match('/^:[A-Za-z_][A-Za-z0-9_]*$/', $placeholder)) {
            throw new InternalErrorException(
                "JsonPath::withParameter() takes a parameter placeholder (:name or ?), got \"$placeholder\""
            );
        }
        return new static(
            [...$this->segments, $placeholder],
            [...$this->parameterSegmentIndexes, count($this->segments)]
        );
    }

    /** The path as a MariaDB JSON path expression: a string literal, or a CONCAT() when a segment is a parameter. */
    public function toMariaDbPathSql(): string
    {
        $pathParts = [];
        $literalPart = '$';
        foreach ($this->segments as $index => $segment) {
            if (in_array($index, $this->parameterSegmentIndexes, true)) {
                $pathParts[] = "'" . $literalPart . ".'";
                $pathParts[] = "JSON_QUOTE($segment)";
                $literalPart = '';
                continue;
            }
            $quotedKey = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $segment) . '"';
            $literalPart .= '.' . str_replace(['\\', "'"], ['\\\\', "''"], $quotedKey);
        }
        if ($literalPart !== '') {
            $pathParts[] = "'" . $literalPart . "'";
        }
        return count($pathParts) === 1 ? $pathParts[0] : 'CONCAT(' . implode(', ', $pathParts) . ')';
    }

    /** The path as a PostgreSQL text array. */
    public function toPostgresPathSql(): string
    {
        $elements = [];
        foreach ($this->segments as $index => $segment) {
            $elements[] = in_array($index, $this->parameterSegmentIndexes, true)
                ? "CAST($segment AS text)"
                : "'" . str_replace("'", "''", $segment) . "'";
        }
        return $elements === [] ? "CAST('{}' AS text[])" : 'ARRAY[' . implode(', ', $elements) . ']::text[]';
    }

    /** Every instance is its own element. */
    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
