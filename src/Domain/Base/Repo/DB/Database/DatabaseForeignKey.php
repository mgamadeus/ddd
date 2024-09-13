<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;
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

    /** @var string Action on update of foreign row key column */
    #[Choice([self::ACTION_CASCADE, self::ACTION_NO_ACTION, self::ACTION_SET_DEFAULT, self::ACTION_SET_NULL, self::ACTION_RESTRICT])]
    public string $onUpdateAction = self::ACTION_CASCADE;

    /** @var string Action on deletion of foreign row */
    #[Choice([self::ACTION_CASCADE, self::ACTION_NO_ACTION, self::ACTION_SET_DEFAULT, self::ACTION_SET_NULL, self::ACTION_RESTRICT])]
    public string $onDeleteAction = self::ACTION_CASCADE;

    /** @var bool If false, foreign key is not used */
    public bool $applyForeignKeyConstraint = true;

    public function getSql(string $tableName): string
    {
        return "ADD CONSTRAINT `fk_{$tableName}_{$this->internalIdColumn}` FOREIGN KEY IF NOT EXISTS (`{$this->internalIdColumn}`) REFERENCES `{$this->foreignTable}` (`{$this->foreignIdColumn}`) ON UPDATE {$this->onUpdateAction} ON DELETE {$this->onDeleteAction}";
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