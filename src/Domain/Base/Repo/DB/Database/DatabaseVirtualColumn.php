<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;

use function PHPUnit\Framework\stringStartsWith;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DatabaseVirtualColumn extends ValueObject
{
    use BaseAttributeTrait;

    /** @var string Prefix for the virtual column, e.g. VirtualAccountId */
    public const string VIRTUAL_COLUMN_PREFIX = 'virtual';

    /** @var DatabaseColumn|null The column the virtual column is based on */
    public ?DatabaseColumn $referenceColumn = null;

    /** @var string The generation instruction for the virtual column */
    public string $as;

    /** @var bool If true, the column is stored */
    public bool $stored = true;

    /** @var bool If true, an index for the virtual column will be generated */
    public bool $createIndex = false;

    /**
     * Optional explicit virtual column name (used when the name cannot be derived from referenceColumn.name).
     * Example: virtualNameSearch.
     */
    public ?string $virtualColumnNameOverride = null;

    /**
     * Set only by {@see DatabaseModel::fromEntityClass()} for the `virtual{Prop}Search` column it generates from
     * `#[Translatable(fullTextIndex: true)]`. `$as` on that one column is the MariaDB-only
     * JSON_UNQUOTE/REGEXP_REPLACE template (DatabaseModel.php) — PostgreSQL's `PostgresDdlRenderer` recognises this
     * flag and renders `GENERATED ALWAYS AS (ddd_translatable_text("col")) STORED` instead of attempting to
     * translate `$as`. Every OTHER virtual column's `$as` is still MariaDB-only raw SQL with no PostgreSQL form
     * until `VirtualExpr` (dual-dialect architecture plan §4.10, build step 7) — `PostgresDdlRenderer` refuses those.
     */
    public bool $isTranslatableFullTextSearchColumn = false;

    /**
     * Set together with {@see $isTranslatableFullTextSearchColumn}: the name of the JSON Translatable column this
     * search column is derived from (`referenceColumn->name` is `{col}Search`, a renamed clone used only for its
     * SQL type — NOT the base column — so the base name is carried separately).
     */
    public ?string $translatableBaseColumnName = null;

    public function getSql(bool $asUpdate = false): string
    {
        $sql = $asUpdate ? 'ADD COLUMN IF NOT EXISTS ' : '';
        if (isset($this->sqlDefaultValue)) {
            if (is_string($this->sqlDefaultValue)) {
            } else {
            }
        } elseif ($this->allowsNull) {
        }

        $sql .= '`' . $this->getName()
            . '` ' . $this->referenceColumn->getSqlType()
            . ' GENERATED ALWAYS AS ' . $this->as . ($this->stored ? ' STORED' : '');
        return $sql;
    }

    public function getName(): string
    {
        if ($this->virtualColumnNameOverride) {
            return $this->virtualColumnNameOverride;
        }
        return self::getVirtualColumnName($this->referenceColumn->name);
    }

    public static function getVirtualColumnName(string $columnName): string {
        return self::VIRTUAL_COLUMN_PREFIX . ucfirst($columnName);
    }

    public static function getColumnNameForVirtualColumn(string $virtualColumnName): string {
        if (!stringStartsWith($virtualColumnName, self::VIRTUAL_COLUMN_PREFIX)) {
            return $virtualColumnName;
        }
        $columnName = substr($virtualColumnName, strlen(self::VIRTUAL_COLUMN_PREFIX));
        return lcfirst($columnName);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->referenceColumn . '_' . $this->as);
    }

    /**
     * @param string $as
     * @param bool $stored
     * @param bool $createIndex
     */
    public function __construct(
        string $as,
        bool $stored = true,
        bool $createIndex = false
    ) {
        $this->as = $as;
        $this->stored = $stored;
        $this->createIndex = $createIndex;
        parent::__construct();
    }
}
