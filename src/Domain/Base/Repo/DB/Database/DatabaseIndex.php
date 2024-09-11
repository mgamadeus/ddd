<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class DatabaseIndex extends ValueObject
{
    use BaseAttributeTrait;

    public const TYPE_NONE = 'NONE';
    public const TYPE_INDEX = 'INDEX';
    public const TYPE_UNIQUE = 'UNIQUE INDEX';
    public const TYPE_FULLTEXT = 'FULLTEXT INDEX';
    public const TYPE_SPATIAL = 'SPATIAL INDEX';

    public const TYPE_NAME_ALLOCATION = [
        self::TYPE_INDEX => 'idx',
        self::TYPE_SPATIAL => 'spx',
        self::TYPE_UNIQUE => 'uniq',
        self::TYPE_FULLTEXT => 'ft'
    ];

    /** @var string Type of index */
    #[Choice([self::TYPE_INDEX, self::TYPE_UNIQUE, self::TYPE_FULLTEXT])]
    public string $indexType = self::TYPE_INDEX;

    /** @var string[] All columns in the index */
    public array $indexColumns = [];

    public function getSql(string $tableName): string
    {
        $indexName = self::TYPE_NAME_ALLOCATION[$this->indexType] . '_';
        if (count($this->indexColumns) == 1) {
            $indexName .= $this->indexColumns[0];
        } else {
            $columnsShort = '';
            foreach ($this->indexColumns as $indexColumn) {
                // in case of virtual Columns e.g. virtualAccountid, we use vAcc instead of vir
                $currentColumnShort = str_starts_with($indexColumn, DatabaseVirtualColumn::VIRTUAL_COLUMN_PREFIX)?substr(DatabaseVirtualColumn::VIRTUAL_COLUMN_PREFIX,0,1) . substr($indexColumn, strlen(DatabaseVirtualColumn::VIRTUAL_COLUMN_PREFIX), 3):substr($indexColumn, 0, 3);
                $columnsShort .= ($columnsShort ? '_' : '') . $currentColumnShort;
            }
            $indexName .= $columnsShort;
        }

        return "CREATE {$this->indexType} IF NOT EXISTS `{$indexName}` ON `{$tableName}` (" . implode(
                ',',
                array_map(function (string $el) {
                    return "`$el`";
                }, $this->indexColumns)
            ) . ')';
    }

    public function uniqueKey(): string
    {
        $key = implode(',', $this->indexColumns) .'_'. $this->indexType;
        return self::uniqueKeyStatic($key);
    }

    /**
     * @param string $indexType
     * @param array $indexColumns
     */
    public function __construct(
        string $indexType = self::TYPE_INDEX,
        array $indexColumns = []
    ) {
        $this->indexType = $indexType;
        $this->indexColumns = $indexColumns;
        parent::__construct();
    }
}