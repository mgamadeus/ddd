<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * VECTOR(N) doctrine type — MariaDB's native `VECTOR(N)` or PostgreSQL's pgvector `vector(N)` (dual-dialect
 * architecture plan §4.9).
 *
 * PHP value: array<float>
 *
 * Persistence:
 * - Parameter value is stored as a JSON array string.
 * - MariaDB wraps the bound parameter with `VEC_FromText(<json-array-string>)`; PostgreSQL binds the JSON array
 *   string bare (`?`) — pgvector's input function parses `[1,2,3]`-shaped text directly, and DDL's write path
 *   (`WriteExecutor`/`BindExpressionKind::VECTOR`, §4.3/§4.6) never calls into this Doctrine Type for a write at
 *   all, so this branch only matters for a plain `EntityManager::persist()`/`flush()` on PostgreSQL.
 *
 * Read path diverges: MariaDB returns `VECTOR` columns as packed float32 binary (`unpack('g*', …)`, unchanged,
 * golden-sensitive); PostgreSQL's pgvector returns the text form (`"[1,2,3]"`) for a plain `SELECT`, which is
 * parsed and each component narrowed through a float32 round trip (`unpack('g', pack('g', (float)$x))[1]`) so PHP
 * sees the SAME doubles MariaDB's binary unpack would have produced for the same stored vector.
 */
class VectorType extends Type
{
    public const string NAME = 'vector';

    protected static bool $enabled = false;

    protected static ?string $enabledForServerVersion = null;

    public static function enable(?string $serverVersion = null): void
    {
        self::$enabled = true;
        self::$enabledForServerVersion = $serverVersion;
    }

    public static function disable(): void
    {
        self::$enabled = false;
        self::$enabledForServerVersion = null;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return [self::NAME];
    }

    public function convertToPHPValue(mixed $binaryValue, AbstractPlatform $platform): ?array
    {
        // Reading VECTOR columns is typically not needed for KNN queries.
        // If MariaDB returns a textual representation like "[1,2,3]" this will parse it.
        if ($binaryValue === null) {
            return null;
        }
        if (!is_string($binaryValue)) {
            return [];
        }

        if (!$platform instanceof MariaDBPlatform) {
            // PostgreSQL/pgvector (§4.9): a plain SELECT returns the text form "[1,2,3]" (no packed binary to
            // unpack). Each component is round-tripped through float32 (pack/unpack 'g') so PHP ends up with the
            // SAME double MariaDB's binary unpack('g*', …) below would produce for the identical stored vector —
            // pgvector itself stores float32 components, same as MariaDB's VECTOR type.
            $trimmed = trim($binaryValue);
            if ($trimmed === '' || $trimmed === '[]') {
                return [];
            }
            if ($trimmed[0] === '[' && str_ends_with($trimmed, ']')) {
                $trimmed = trim($trimmed, '[] ');
                if ($trimmed === '') {
                    return [];
                }
                return array_map(
                    static fn(string $component): float => unpack('g', pack('g', (float)$component))[1],
                    explode(',', $trimmed)
                );
            }
            return [];
        }

        $value = unpack('g*', $binaryValue);
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            if ($trimmed[0] === '[' && str_ends_with($trimmed, ']')) {
                $trimmed = trim($trimmed, '[] ');
                if ($trimmed === '') {
                    return [];
                }
                return array_map('floatval', explode(',', $trimmed));
            }
        }

        if (is_array($value)) {
            return array_values(array_map('floatval', $value));
        }

        return [];
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            // Accept raw JSON string ("[...]")
            return $value;
        }

        if (!is_array($value) || $value === []) {
            return null;
        }

        $values = [];
        foreach ($value as $v) {
            if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
                $values[] = (float)$v;
            }
        }

        if ($values === []) {
            return null;
        }

        // JSON array string accepted by VEC_FromText().
        return json_encode($values, JSON_THROW_ON_ERROR);
    }

    public function canRequireSQLConversion(): bool
    {
        return true;
    }

    /**
     * MariaDB wraps the bound parameter with VEC_FromText(); PostgreSQL/pgvector binds the JSON array string bare
     * (§4.9 — `convertToDatabaseValue()` above already produced it, and pgvector's input function parses the
     * `[1,2,3]` text form directly, no wrapping function needed).
     *
     * @throws Exception
     */
    public function convertToDatabaseValueSQL($sqlExpr, AbstractPlatform $platform): string
    {
        if (!$platform instanceof MariaDBPlatform) {
            return $sqlExpr;
        }
        self::assertMariaVectorSupported($platform);
        return sprintf('VEC_FromText(%s)', $sqlExpr);
    }

    /**
     * @throws Exception
     */
    protected static function assertMariaVectorSupported(AbstractPlatform $platform): void
    {
        if (!$platform instanceof MariaDBPlatform) {
            throw new Exception('MariaVector: VECTOR type is only supported on MariaDBPlatform.');
        }

        if (!self::$enabled) {
            $suffix = self::$enabledForServerVersion ? (' (server_version=' . self::$enabledForServerVersion . ')') : '';
            throw new Exception('MariaVector: VECTOR type is disabled for this connection' . $suffix . '.');
        }
    }

    /**
     * MariaDB keeps its `VECTOR(N)` declaration (unchanged, golden-sensitive, via
     * {@see self::assertMariaVectorSupported()}); PostgreSQL renders pgvector's `vector(N)` (§4.9) — this method
     * is dead for DDD's own DDL (generated by {@see \DDD\Domain\Base\Repo\DB\Dialect\Schema\PostgresDdlRenderer}
     * via {@see \DDD\Domain\Base\Repo\DB\Database\DatabaseColumn::vectorDimensions}, never Doctrine's schema
     * tool), kept correct only so a stray `EntityManager`-driven schema call never throws for PostgreSQL.
     *
     * @throws Exception
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $dim = $column['length'] ?? $column['dim'] ?? null;
        if (!$dim) {
            throw new Exception(
                "VECTOR(N) requires a dimension. Provide it via ORM Column length (e.g. #[ORM\\Column(type: 'vector', length: 1536)])."
            );
        }

        if (!$platform instanceof MariaDBPlatform) {
            return 'vector(' . (int)$dim . ')';
        }
        self::assertMariaVectorSupported($platform);

        return 'VECTOR(' . (int)$dim . ')';
    }
}

