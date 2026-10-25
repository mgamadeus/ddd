<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Write;

/**
 * What SQL fragment a bound value needs around its `?` placeholder (dual-dialect architecture plan §4.3
 * `bindExpression()`). Mirrors the three branches
 * {@see \DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager::upsert()} already has for MariaDB
 * (`DatabaseColumn::SPATIAL_SQL_TYPES[$type]`, `$type == 'vector'`, else plain).
 */
enum BindExpressionKind
{
    case PLAIN;
    case GEOMETRY;
    case VECTOR;
}
