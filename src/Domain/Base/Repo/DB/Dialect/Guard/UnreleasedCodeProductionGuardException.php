<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Guard;

use RuntimeException;

/** Thrown by {@see UnreleasedCodeGuard} when unreleased DDD code is about to connect to what looks like production. */
class UnreleasedCodeProductionGuardException extends RuntimeException
{
}
