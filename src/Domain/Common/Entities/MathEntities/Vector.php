<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\MathEntities;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use Override;

/**
 * Vector value object used for database VECTOR columns.
 *
 * It is intentionally lightweight: it only stores the numeric values.
 */
class Vector extends ValueObject
{
    public CONST DIMENSION_OPENAI_EMBEDDING_SMALL = 1536;

    public CONST DIMENSION_OPENAI_EMBEDDING_LARGE = 3072;

    /** @var array|float[]|null  */
    #[HideProperty]
    public ?array $vectorValues = [];

    /**
     * @param array<float>|null $vectorValues
     */
    public function __construct(?array $vectorValues = [])
    {
        parent::__construct();
        // intentionally leave out parent constructor call for performance reasons
        $this->vectorValues = $vectorValues ?? [];
    }

    public function __toString(): string
    {
        if (!$this->vectorValues) {
            return '';
        }

        return '[' . implode(',', array_map(static fn($v) => (string)(float)$v, $this->vectorValues)) . ']';
    }

    /**
     * Convenience constructor.
     *
     * @param array<float>|null $vectorValues
     */
    public static function fromArray(?array $vectorValues): self
    {
        return new self($vectorValues ?? []);
    }

    public function uniqueKey(): string
    {
        $values = $this->vectorValues ?? [];
        $values = array_map(static fn($v) => (float)$v, $values);

        // Stable hash of values; sufficient for value-object equality.
        return self::uniqueKeyStatic(md5(json_encode($values)));
    }

    #[Override]
    public function mapFromRepository(mixed $repoObject): void
    {
        // The DBAL type persists as a JSON array string and converts to array<float>.
        if ($repoObject === null) {
            $this->vectorValues = [];
            return;
        }

        if (is_array($repoObject)) {
            $this->vectorValues = array_map(static fn($v) => (float)$v, $repoObject);
            return;
        }

        if (is_string($repoObject)) {
            $decoded = json_decode($repoObject, true);
            if (is_array($decoded)) {
                $this->vectorValues = array_map(static fn($v) => (float)$v, $decoded);
                return;
            }

            // Fallback: parse bracket format "[1,2,3]".
            $trimmed = trim($repoObject);
            $trimmed = trim($trimmed, "[] \t\n\r\0\x0B");
            if ($trimmed === '') {
                $this->vectorValues = [];
                return;
            }

            $parts = explode(',', $trimmed);
            $this->vectorValues = array_map(static fn($v) => (float)$v, $parts);
            return;
        }

        if (is_object($repoObject)) {
            // Try common property names.
            if (isset($repoObject->vectorValues) && is_array($repoObject->vectorValues)) {
                $this->vectorValues = array_map(static fn($v) => (float)$v, $repoObject->vectorValues);
                return;
            }
            if (isset($repoObject->values) && is_array($repoObject->values)) {
                $this->vectorValues = array_map(static fn($v) => (float)$v, $repoObject->values);
                return;
            }
        }

        $this->vectorValues = [];
    }

    /**
     * @return array<float>|null
     */
    public function mapToRepository(): mixed
    {
        // Repository should receive the raw numeric array; DBAL type will handle SQL conversion.
        return $this->vectorValues ?: null;
    }
}

