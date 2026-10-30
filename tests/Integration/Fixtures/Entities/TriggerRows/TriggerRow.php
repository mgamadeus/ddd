<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Fixtures\Entities\TriggerRows;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseTrigger;
use DDD\Tests\Integration\Fixtures\Repo\DB\TriggerRows\DBTriggerRow;

/**
 * Minimal `DatabaseTrigger` fixture (Arch §4.10 step 7 trigger slice, dual-dialect architecture plan
 * `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md`): a BEFORE INSERT trigger refuses a second
 * `active` row sharing the same `code`. Deliberately NOT in {@see \DDD\Tests\Integration\IntegrationTestCase::FIXTURE_ENTITY_CLASSES}
 * — that shared list backs every other integration test class via a MariaDB bootstrap path
 * ({@see \DDD\Tests\Integration\IntegrationTestCase::splitSqlStatements()}) that is a plain `explode`-style split,
 * which this entity's trigger body would break; {@see \DDD\Tests\Integration\Schema\TriggerRowTest} manages this
 * fixture's table on its own, through {@see \DDD\Domain\Base\Repo\DB\Dialect\Schema\SqlScriptLexer} instead.
 *
 * Small enough to port 1:1 to PostgreSQL by hand (unlike the Radbonus `Track` trigger this mirrors in shape:
 * MariaDB `BEGIN…END` + `SIGNAL`, PostgreSQL `CREATE OR REPLACE FUNCTION` + `RAISE EXCEPTION`), while still
 * exercising the real per-dialect file resolution ({@see DatabaseTrigger::getSqlForDialect()}) and the lexer split
 * on both engines.
 */
#[DatabaseTrigger(DatabaseTrigger::EXECUTE_BEFORE, [DatabaseTrigger::OPERATION_INSERT])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBTriggerRow::class)]
#[QueryOptions]
class TriggerRow extends Entity
{
    use QueryOptionsTrait;

    /** @var string|null Business code; a second active row with the same code is refused by the trigger */
    public ?string $code;

    /** @var bool Only an active row counts as a conflict */
    public bool $active = true;
}
