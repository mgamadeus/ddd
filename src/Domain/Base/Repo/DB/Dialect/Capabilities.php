<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

/**
 * What a dialect can do, keyed off its driver name — a stateless value object (plan rule 3: never process-global).
 * Grows per build step as features become engine-conditional (fulltext mode rendering, vector index kind, …);
 * step 1/2 only need to tell the two engines apart.
 */
class Capabilities
{
    public function __construct(protected readonly string $driver)
    {
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isMariaDb(): bool
    {
        return $this->driver === SqlDialect::MARIADB;
    }

    public function isPostgres(): bool
    {
        return $this->driver === SqlDialect::POSTGRES;
    }
}
