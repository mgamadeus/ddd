<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Json;

/**
 * What a SQL expression written into a JSON document is ({@see JsonDocumentUpdate::set()}): the engines need to be
 * told, since a bound parameter carries no JSON type of its own.
 */
enum JsonValueKind
{
    /** Text: stored as a JSON string, also when it looks like a number. */
    case TEXT;

    /** A numeric SQL expression (a column, an arithmetic expression, `CAST(:amount AS DECIMAL(16,8))`): a JSON number. */
    case NUMBER;

    /** A boolean SQL expression: JSON true / false (SQL NULL counts as false). */
    case BOOLEAN;

    /** JSON text (a bound encoded value, another JSON column): stored as that JSON value. */
    case JSON;
}
