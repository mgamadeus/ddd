<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

use Doctrine\ORM\Configuration;

/**
 * One facade per database engine (D9, architecture plan `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md`
 * §4.3). This is the step 1/2 slice of the full interface the plan describes: only what
 * {@see \DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory::create()} and the integration harness need today
 * (DQL function/type registration, connection-param shaping, identifier quoting). Later build steps (3-9) add
 * `upsertBuilder()`, `pagination()`, `ddlRenderer()`, `introspector()`, `infrastructure()`, `locks()` and the
 * app-facing SQL-fragment helpers as their collaborators are built — adding methods to this interface is additive,
 * never a reason to stub the ones that are not needed yet.
 */
interface SqlDialect
{
    public const string MARIADB = 'mariadb';
    public const string POSTGRES = 'postgres';

    public function name(): string;

    public function capabilities(): Capabilities;

    /** DQL functions, types; walkers/middlewares/quote strategy land as those collaborators are built (step 5+). */
    public function configureOrm(Configuration $configuration): void;

    /** @param array<string, mixed> $params @return array<string, mixed> */
    public function connectionParams(array $params): array;

    /** Quotes a single identifier for raw SQL on this engine (`` `x` `` | `"x"`). */
    public function quote(string $identifier): string;
}
