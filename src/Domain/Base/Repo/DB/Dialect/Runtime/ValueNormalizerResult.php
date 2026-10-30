<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Runtime;

use Doctrine\DBAL\Driver\Middleware\AbstractResultMiddleware;

/** @see ValueNormalizerMiddleware */
class ValueNormalizerResult extends AbstractResultMiddleware
{
    public function fetchNumeric()
    {
        $row = parent::fetchNumeric();

        return $row === false ? false : self::normalizeRow($row);
    }

    public function fetchAssociative()
    {
        $row = parent::fetchAssociative();

        return $row === false ? false : self::normalizeRow($row);
    }

    public function fetchOne()
    {
        $value = parent::fetchOne();

        return $value === false ? false : self::normalizeValue($value);
    }

    public function fetchAllNumeric(): array
    {
        return array_map(self::normalizeRow(...), parent::fetchAllNumeric());
    }

    public function fetchAllAssociative(): array
    {
        return array_map(self::normalizeRow(...), parent::fetchAllAssociative());
    }

    public function fetchFirstColumn(): array
    {
        return array_map(self::normalizeValue(...), parent::fetchFirstColumn());
    }

    /** @param array<int|string, mixed> $row @return array<int|string, mixed> */
    protected static function normalizeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            $row[$key] = self::normalizeValue($value);
        }

        return $row;
    }

    protected static function normalizeValue(mixed $value): mixed
    {
        if (is_resource($value)) {
            return stream_get_contents($value);
        }
        if (is_bool($value)) {
            return (int)$value;
        }

        return $value;
    }
}
