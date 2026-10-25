<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Validation\Constraints\Choice;
use ReflectionException;

/**
 * Entities with Trigger Attributes will include the trigger in Database Model Generation
 * SQL for Trigger is expected to be named the follwoing way:
 * e.g. Entity with Trigger Attribute applied:
 *
 * - Domain/Accounts/Entities/Tracks/Track.php
 * - Trigger executionOrder: BEFORE
 * - Trigger executeOnOperation: [INSERT]
 * => Trigger is excpected to be placed
 * Domain/Accounts/Repo/Doctrine/Tracks/TrackBeforeInsertTrigger.sql
 *
 * multiple Operations are concatenated with And e.g. TrackBeforeInsertAndUpdateTrigger.sql
 *
 * **Dual-dialect architecture plan §4.10** (`agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md`):
 * every trigger ships one source file PER dialect, same base name, dialect-specific suffix —
 * `TrackBeforeInsertTrigger.sql` (MariaDB, unchanged) next to `TrackBeforeInsertTrigger.pgsql.sql` (PostgreSQL:
 * `CREATE OR REPLACE FUNCTION` + `CREATE TRIGGER`, split by {@see \DDD\Domain\Base\Repo\DB\Dialect\Schema\SqlScriptLexer}).
 * A missing file FAILS generation ({@see getSqlForDialect()}) — unlike the pre-dual-dialect behaviour, which
 * returned an empty string.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class DatabaseTrigger extends ValueObject
{
    use BaseAttributeTrait;

    public const string EXECUTE_BEFORE = 'BEFORE';

    public const string EXECUTE_AFTER = 'AFTER';

    public const string OPERATION_INSERT = 'INSERT';

    public const string OPERATION_UPDATE = 'UPDATE';

    public const string OPERATION_DELETE = 'DELETE';

    /** @var string When to execute the trigger */
    #[Choice([self::EXECUTE_BEFORE, self::EXECUTE_AFTER])]
    public string $exectutionOrder = self::EXECUTE_BEFORE;

    /** @var array|string[] The operations on which to execute the trigger */
    public array $executeOnOperations = [self::OPERATION_INSERT];

    /**
     */
    public function __construct(string $exectutionOrder, array $executeOnOperations)
    {
        $this->exectutionOrder = $exectutionOrder;
        $this->executeOnOperations = $executeOnOperations;
        parent::__construct();
    }

    /**
     * Repo/DB directory the trigger's source file(s) live in, mirroring the entity's own folder
     * (`Domain/{X}/Entities/{Y}` -> `Domain/{X}/Repo/DB/{Y}`).
     *
     * @throws ReflectionException
     */
    public function getTriggerDirectory(ClassWithNamespace $classOnWhichTriggerIsApplied): string
    {
        $fileName = ReflectionClass::instance($classOnWhichTriggerIsApplied->getNameWithNamespace())->getFileName();
        $fileNameExplode = explode('/', $fileName);
        array_pop($fileNameExplode);
        foreach ($fileNameExplode as $index => $folder) {
            if ($folder == 'Entities') {
                $fileNameExplode[$index] = 'Repo/DB';
            }
        }
        return implode('/', $fileNameExplode);
    }

    /**
     * File-name base shared by both dialects' trigger source files (e.g. "TrackBeforeInsertTrigger") —
     * {@see getTriggerFilePath()} appends the per-dialect suffix.
     */
    public function getTriggerFileBaseName(ClassWithNamespace $classOnWhichTriggerIsApplied): string
    {
        return $classOnWhichTriggerIsApplied->name . ucfirst(strtolower($this->exectutionOrder)) . implode(
            'And',
            array_map(function ($operations) {
                return ucfirst(strtolower($operations));
            }, $this->executeOnOperations)
        ) . 'Trigger';
    }

    /**
     * Full path to this trigger's per-dialect source file (Arch §4.10: `{Entity}{Name}Trigger.sql` MariaDB /
     * `….pgsql.sql` PostgreSQL) — does not check existence.
     *
     * @throws ReflectionException
     */
    public function getTriggerFilePath(ClassWithNamespace $classOnWhichTriggerIsApplied, string $dialect = SqlDialect::MARIADB): string
    {
        $suffix = match ($dialect) {
            SqlDialect::MARIADB => '.sql',
            SqlDialect::POSTGRES => '.pgsql.sql',
            default => throw new InternalErrorException("DatabaseTrigger: unknown dialect \"$dialect\"."),
        };
        return $this->getTriggerDirectory($classOnWhichTriggerIsApplied) . '/'
            . $this->getTriggerFileBaseName($classOnWhichTriggerIsApplied) . $suffix;
    }

    /**
     * Reads this trigger's source SQL for one dialect. Arch §4.10 "missing file fails generation": a missing file
     * is a hard generation error, not a silently empty trigger.
     *
     * @throws ReflectionException
     */
    public function getSqlForDialect(ClassWithNamespace $classOnWhichTriggerIsApplied, string $dialect): string
    {
        $path = $this->getTriggerFilePath($classOnWhichTriggerIsApplied, $dialect);
        if (!file_exists($path)) {
            throw new InternalErrorException(
                "DatabaseTrigger: no $dialect trigger source file for "
                . $classOnWhichTriggerIsApplied->getNameWithNamespace() . " at \"$path\" "
                . '(Arch §4.10: missing file fails generation).'
            );
        }
        return file_get_contents($path);
    }

    /**
     * MariaDB trigger SQL — the dialect {@see \DDD\Domain\Base\Repo\DB\Database\DatabaseModel::getSql()} (the
     * golden path) has always targeted.
     *
     * @param ClassWithNamespace $classOnWhichTriggerIsApplied
     * @return string
     * @throws ReflectionException
     */
    public function getSql(ClassWithNamespace $classOnWhichTriggerIsApplied): string
    {
        return $this->getSqlForDialect($classOnWhichTriggerIsApplied, SqlDialect::MARIADB);
    }

    public function uniqueKey(): string
    {
        return parent::uniqueKey($this->exectutionOrder . implode($this->executeOnOperations));
    }

}