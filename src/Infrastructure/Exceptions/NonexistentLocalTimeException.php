<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Exceptions;

use DateTimeZone;

/**
 * A canonical local time that does not exist in its zone, i.e. it falls into the hour clocks jump over on a
 * spring-forward day. PHP would silently shift such a reading to the next existing time; refusing it instead lets
 * the caller (a human or a model writing a schedule) correct the input rather than act on a moment it never meant.
 */
class NonexistentLocalTimeException extends BadRequestException
{
    public function __construct(string $stringFormattedDate, DateTimeZone $timezone, string $nextExistingLocalTime)
    {
        parent::__construct(
            sprintf(
                '"%s" does not exist in %s (clocks jump forward there) — the next existing local time is %s.',
                $stringFormattedDate,
                $timezone->getName(),
                $nextExistingLocalTime
            )
        );
    }
}
