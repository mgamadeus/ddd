<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Infrastructure\Validation\Constraints\Choice;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DatabaseForeignKey extends ValueObject
{
    use BaseAttributeTrait;

    /** @var string Cascades deletion if foreign Key Entity is deleted */
    public const string ACTION_CASCADE = 'CASCADE';

    /** @var string Changes nothing, usualy not a good idea */
    public const string ACTION_NO_ACTION = 'NO ACTION';

    /** @var string Sets foreign key reference column to null, especially relevant if Entity is far more essential, than foreign entity */
    public const string ACTION_SET_NULL = 'SET NULL';

    /** @var string Deletion of foreign Entity is prohibited until reference to it is removed */
    public const string ACTION_RESTRICT = 'RESTRICT';

    /** @var string Sets value to default usually in case of update; ATTENTION: not supported by many DBMS! */
    public const string ACTION_SET_DEFAULT = 'SET DEFAULT';

    /**
     * MariaDB: the ON UPDATE actions a FK may not have when its column is the base of a STORED generated column.
     * Each fails with `ERROR 1901 … '<column>' cannot be used in the GENERATED ALWAYS AS clause of '<stored column>'`.
     */
    public const array MARIADB_ON_UPDATE_ACTIONS_FORBIDDEN_ON_STORED_GENERATED_COLUMN_BASE = [
        self::ACTION_CASCADE,
        self::ACTION_SET_NULL,
        self::ACTION_SET_DEFAULT,
    ];

    /**
     * MariaDB: the ON DELETE actions a FK may not have when its column is the base of a STORED generated column
     * (`ERROR 1901` as above). ON DELETE CASCADE is allowed: it deletes the row and never writes the base column.
     */
    public const array MARIADB_ON_DELETE_ACTIONS_FORBIDDEN_ON_STORED_GENERATED_COLUMN_BASE = [
        self::ACTION_SET_NULL,
        self::ACTION_SET_DEFAULT,
    ];

    /** @var string Internal column containing id, e.g. cityId */
    public string $internalIdColumn;

    /** @var string Internal column containing Entity, e.g. city */
    public string $internalColumn;

    /** @var string Foreign table to reference, e.g. Cities */
    public string $foreignTable;

    /** @var string Foreign Model class to reference, e.g. CityModel */
    public string $foreignModelClassName;

    /** @var string Column in foreign table to reference, e.g. id */
    public string $foreignIdColumn = 'id';

    /** @var bool|null True if the property carrying this FK declares #[LazyLoad(addAsParent: true)] */
    public ?bool $representsParentRelation = null;

    /** @var string Action on update of foreign row key column */
    #[Choice([self::ACTION_CASCADE, self::ACTION_NO_ACTION, self::ACTION_SET_DEFAULT, self::ACTION_SET_NULL, self::ACTION_RESTRICT])]
    public string $onUpdateAction = self::ACTION_CASCADE;

    /** @var string Action on deletion of foreign row */
    #[Choice([self::ACTION_CASCADE, self::ACTION_NO_ACTION, self::ACTION_SET_DEFAULT, self::ACTION_SET_NULL, self::ACTION_RESTRICT])]
    public string $onDeleteAction = self::ACTION_CASCADE;

    /** @var bool If false, foreign key is not used */
    public bool $applyForeignKeyConstraint = true;

    /**
     * @var bool True when the FK column is the base of a STORED generated column of its table — set by
     *     {@see DatabaseModel}, never by the attribute. Such columns exist for composite UNIQUE indexes over a nullable
     *     FK: `IFNULL(teamId, 0)` STORED makes NULL a comparable value, so the unique index holds for rows without a
     *     team. MariaDB then restricts the FK's actions ({@see getOnUpdateActionForDialect()}); PostgreSQL does not.
     */
    public bool $isBaseOfStoredGeneratedColumn = false;

    /**
     * @param string $tableName Owning table name (used to synthesize the default constraint name).
     * @param string|null $constraintNameOverride When provided, used verbatim as the constraint
     *     name instead of the default `fk_{table}_{column}` pattern. Used by the schema-diff
     *     service on MODIFY to preserve the live constraint name (including pt-osc's cosmetic
     *     `_` prefix), avoiding a rename ping-pong every diff cycle.
     */
    public function getSql(string $tableName, ?string $constraintNameOverride = null): string
    {
        // The override (the LIVE constraint name) is used verbatim — it exists, so it is within the cap.
        // A synthesized default name is capped at MariaDB's 64-char identifier limit (error 1059 otherwise).
        $constraintName = $constraintNameOverride !== null && $constraintNameOverride !== ''
            ? $constraintNameOverride
            : DatabaseIdentifier::shortenToMaxLength("fk_{$tableName}_{$this->internalIdColumn}");
        return "ADD CONSTRAINT `{$constraintName}` FOREIGN KEY IF NOT EXISTS (`$this->internalIdColumn`) REFERENCES `$this->foreignTable` (`$this->foreignIdColumn`) ON UPDATE {$this->getOnUpdateActionForDialect(SqlDialect::MARIADB)} ON DELETE {$this->getOnDeleteActionForDialect(SqlDialect::MARIADB)}"
            . ($this->requiresCopyAlgorithmOnMariaDb() ? ', ALGORITHM=COPY' : '');
    }

    /**
     * True when MariaDB can add this FK only with `ALGORITHM=COPY`: ON DELETE CASCADE on the base of a STORED generated
     * column. The in-place algorithm refuses it with `ERROR 138 Cannot add foreign key on the base column of stored
     * column`, and MariaDB picks in-place for ADD FOREIGN KEY whenever `FOREIGN_KEY_CHECKS=0` — which the schema
     * diff's apply sets by default. With the checks on MariaDB copies anyway. COPY rebuilds the table, so the
     * schema diff counts it as a copy-forcing operation on a large table.
     */
    public function requiresCopyAlgorithmOnMariaDb(): bool
    {
        return $this->isBaseOfStoredGeneratedColumn
            && $this->getOnDeleteActionForDialect(SqlDialect::MARIADB) === self::ACTION_CASCADE;
    }

    /**
     * The ON UPDATE action the dialect gets. The declared {@see $onUpdateAction} is the intent; on a FK whose column is
     * the base of a STORED generated column ({@see $isBaseOfStoredGeneratedColumn}) it is downgraded per dialect:
     *
     * | Dialect | ON UPDATE | ON DELETE |
     * |---|---|---|
     * | PostgreSQL | as declared (no restriction: a generated column is recomputed when its base changes) | as declared |
     * | MariaDB | CASCADE / SET NULL / SET DEFAULT → RESTRICT | SET NULL / SET DEFAULT → RESTRICT; CASCADE kept |
     *
     * MariaDB 11.8.5, probed on `C (pId INT NULL, vP INT AS (IFNULL(pId,0)) STORED, UNIQUE(vP))` with a FK
     * `pId → P(id)`: `ON DELETE CASCADE` is created and cascades; `ON DELETE CASCADE ON UPDATE CASCADE` and
     * `ON DELETE SET NULL` fail with `ERROR 1901 … 'pId' cannot be used in the GENERATED ALWAYS AS clause of 'vP'`
     * (an action that WRITES the base column of a stored column); `ON DELETE RESTRICT` is created. `ON DELETE CASCADE`
     * fails with `ERROR 138 Cannot add foreign key on the base column of stored column` only when added in place
     * (`FOREIGN_KEY_CHECKS=0`) — {@see requiresCopyAlgorithmOnMariaDb()}. MySQL 8
     * forbids CASCADE on either action as well — DDD has no MySQL dialect; MySQL is not supported by this rule.
     */
    public function getOnUpdateActionForDialect(string $dialect): string
    {
        if ($this->isBaseOfStoredGeneratedColumn && $dialect === SqlDialect::MARIADB
            && in_array($this->onUpdateAction, self::MARIADB_ON_UPDATE_ACTIONS_FORBIDDEN_ON_STORED_GENERATED_COLUMN_BASE, true)) {
            return self::ACTION_RESTRICT;
        }
        return $this->onUpdateAction;
    }

    /** The ON DELETE action the dialect gets; the rules are at {@see getOnUpdateActionForDialect()} */
    public function getOnDeleteActionForDialect(string $dialect): string
    {
        if ($this->isBaseOfStoredGeneratedColumn && $dialect === SqlDialect::MARIADB
            && in_array($this->onDeleteAction, self::MARIADB_ON_DELETE_ACTIONS_FORBIDDEN_ON_STORED_GENERATED_COLUMN_BASE, true)) {
            return self::ACTION_RESTRICT;
        }
        return $this->onDeleteAction;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->internalIdColumn);
    }

    /**
     * @param bool $applyForeignKeyConstraint
     * @param string $onUpdateAction
     * @param string $onDeleteAction
     */
    public function __construct(
        bool $applyForeignKeyConstraint = true,
        string $onUpdateAction = self::ACTION_CASCADE,
        string $onDeleteAction = self::ACTION_CASCADE
    ) {
        $this->applyForeignKeyConstraint = $applyForeignKeyConstraint;
        $this->onUpdateAction = $onUpdateAction;
        $this->onDeleteAction = $onDeleteAction;
        parent::__construct();
    }

}