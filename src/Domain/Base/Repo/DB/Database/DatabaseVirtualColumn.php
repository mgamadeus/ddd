<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DatabaseVirtualColumn extends ValueObject
{
    use BaseAttributeTrait;

    /** @var string Prefix for the virtual column, e.g. VirtualAccountId */
    public const VIRTUAL_COLUMN_PREFIX = 'virtual';

    /** @var DatabaseColumn|null The column the virtual column is based on */
    public ?DatabaseColumn $referenceColumn = null;

    /** @var string The generation instruction for the virtual column */
    public string $as;

    public function getSql(bool $asUpdate = false): string
    {
        $sql = $asUpdate ? 'ADD COLUMN IF NOT EXISTS ' : '';
        $defaultValue = '';
        if (isset($this->sqlDefaultValue)) {
            if (is_string($this->sqlDefaultValue)) {
                $defaultValue = "'{$this->sqlDefaultValue}'";
            } else {
                $defaultValue = $this->sqlDefaultValue;
            }
        } elseif ($this->allowsNull) {
            $defaultValue = 'NULL';
        }

        $sql .= '`' . $this->getName()
            . '` ' . $this->referenceColumn->getSqlType()
            . ' GENERATED ALWAYS AS ' . $this->as . ' STORED';
        return $sql;
    }

    public function getName(): string
    {
        return self::VIRTUAL_COLUMN_PREFIX . ucfirst($this->referenceColumn->name);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->referenceColumn . '_' . $this->as);
    }

    /**
     * @param string $as
     */
    public function __construct(
        string $as,
    ) {
        $this->as = $as;
        parent::__construct();
    }

}