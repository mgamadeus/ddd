<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect;

use DDD\Domain\Base\Repo\DB\Dialect\Locks\LockService;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\DdlRenderer;
use DDD\Domain\Base\Repo\DB\Dialect\Write\UpsertBuilder;
use Doctrine\DBAL\Connection;
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

    /** Step 4a: the CREATE-path schema renderer (§4.10). ALTER/introspection land in step 7. */
    public function ddlRenderer(): DdlRenderer;

    /**
     * Step 3/4's write-path collaborator (§4.3). Deviation from the plan's zero-arg signature: the builder is
     * stateless (plan rule 3 — never process-global), but its methods execute against a connection, so every
     * `UpsertBuilder` method takes the calling `DoctrineEntityManager` explicitly instead of the dialect caching
     * one per instance.
     */
    public function upsertBuilder(): UpsertBuilder;

    /**
     * Step 8's locks collaborator (§4.3/§4.12, D3): named application locks, equal to MariaDB's
     * `GET_LOCK`/`RELEASE_LOCK` contract on both engines. Deviation from the plan's zero-arg `locks(): LockService`
     * signature, for the same reason as {@see upsertBuilder()}: `MariaDbDialect`/`PostgresDialect` are process-wide
     * stateless singletons shared by every scope ({@see DialectResolver} caches one of each for all of them), so
     * neither can know on its own which scope's connection a lock should run against. `MariaDbDialect` locks the
     * given $connection directly (today's behaviour, unchanged). `PostgresDialect` ignores $connection — a
     * session-scoped advisory lock on a PgBouncer transaction-mode connection leaks and loses mutual exclusion —
     * and opens its own direct, non-pooled connection from $scope's `DB_<SCOPE>_DIRECT_HOST`/`DB_<SCOPE>_DIRECT_PORT`
     * instead.
     */
    public function locks(Connection $connection, string $scope = 'DEFAULT'): LockService;
}
