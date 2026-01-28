<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine\Custom\Types;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * VECTOR(N) doctrine type, e.g. for MariaDB
 *
 * PHP value: array<float>
 *
 * Persistence:
 * - Parameter value is stored as a JSON array string.
 * - SQL conversion wraps the parameter using VEC_FromText(<json-array-string>).
 */
class VectorType extends Type
{
    public const string NAME = 'vector';

    private static bool $enabled = false;

    private static ?string $enabledForServerVersion = null;

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

    public function convertToPHPValue(mixed $binaryValue, AbstractPlatform $platform): array
    {
        // Reading VECTOR columns is typically not needed for KNN queries.
        // If MariaDB returns a textual representation like "[1,2,3]" this will parse it.
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
     * Wrap parameter placeholder using VEC_FromText().
     *
     * @throws Exception
     */
    public function convertToDatabaseValueSQL($sqlExpr, AbstractPlatform $platform): string
    {
        self::assertMariaVectorSupported($platform);
        return sprintf('VEC_FromText(%s)', $sqlExpr);
    }

    /**
     * @throws Exception
     */
    private static function assertMariaVectorSupported(AbstractPlatform $platform): void
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
     * @throws Exception
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        self::assertMariaVectorSupported($platform);

        $dim = $column['length'] ?? $column['dim'] ?? null;
        if (!$dim) {
            throw new Exception(
                "MariaVector: VECTOR(N) requires a dimension. Provide it via ORM Column length (e.g. #[ORM\\Column(type: 'vector', length: 1536)])."
            );
        }

        return 'VECTOR(' . (int)$dim . ')';
    }
}

