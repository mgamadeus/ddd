<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Database;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Encryption\EncryptionScope;
use DDD\Domain\Common\Entities\GeoEntities\GeoPoint;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ReflectionAttribute;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use ReflectionNamedType;
use ReflectionUnionType;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DatabaseColumn extends ValueObject
{
    use BaseAttributeTrait;

    public const PROPERTIES_TO_SKIP = ['objectType' => true, 'changeHistory' => true, 'queryOptions' => true];

    public const MAP_CHOICES_TO_ENUMS = false;

    public const PRIMARY_KEY_NAME = 'id';

    public const SQL_TYPE_INT = 'INT';
    public const SQL_TYPE_BIGINT = 'BIGINT';
    public const SQL_TYPE_FLOAT = 'FLOAT';
    public const SQL_TYPE_DOUBLE = 'DOUBLE';
    public const SQL_TYPE_BOOL = 'BOOLEAN';
    public const SQL_TYPE_VARCHAR = 'VARCHAR';
    public const SQL_TYPE_DATE = 'DATE';
    public const SQL_TYPE_DATETIME = 'DATETIME';
    public const SQL_TYPE_TEXT = 'TEXT';
    public const SQL_TYPE_MEDIUMTEXT = 'MEDIUMTEXT';
    public const SQL_TYPE_LONGTEXT = 'LONGTEXT';
    public const SQL_TYPE_BLOB = 'BLOB';
    public const SQL_TYPE_MEDIUMBLOB = 'MEDIUMBLOB';
    public const SQL_TYPE_LONGBLOB = 'LONGBLOB';
    public const SQL_TYPE_JSON = 'JSON';
    public const SQL_TYPE_POINT = 'POINT';

    public const SQL_TYPE_ALLOCATION = [
        ReflectionClass::INTEGER => self::SQL_TYPE_INT,
        ReflectionClass::FLOAT => self::SQL_TYPE_DOUBLE,
        ReflectionClass::BOOL => self::SQL_TYPE_BOOL,
        ReflectionClass::STRING => self::SQL_TYPE_VARCHAR,
        Date::class => self::SQL_TYPE_DATE,
        DateTime::class => self::SQL_TYPE_DATETIME,
        GeoPoint::class => self::SQL_TYPE_POINT,
        ValueObject::class => self::SQL_TYPE_JSON,
    ];

    public const DOCTRINE_COLUMN_TYPE_ALLOCATIONS = [
        ReflectionClass::BOOL => 'boolean',
        ReflectionClass::INTEGER => 'integer',
        ReflectionClass::STRING => 'string',
        ReflectionClass::FLOAT => 'float',
        DateTime::class => 'datetime',
        Date::class => 'date',
        GeoPoint::class => 'point',
        ValueObject::class => 'json',
    ];

    public const DOCTRINE_SQL_TYPE_ALLOCATIONS = [
        self::SQL_TYPE_BOOL => 'boolean',
        self::SQL_TYPE_INT => 'integer',
        self::SQL_TYPE_VARCHAR => 'string',
        self::SQL_TYPE_TEXT => 'string',
        self::SQL_TYPE_FLOAT => 'float',
        self::SQL_TYPE_DOUBLE => 'float',
        self::SQL_TYPE_DATETIME => 'datetime',
        self::SQL_TYPE_DATE => 'date',
        self::SQL_TYPE_POINT => 'point',
        self::SQL_TYPE_JSON => 'json',
    ];

    public const DOCTRINE_PHP_TYPE_ALLOCATIONS = [
        ReflectionClass::BOOL => 'bool',
        ReflectionClass::INTEGER => 'int',
        ReflectionClass::STRING => 'string',
        ReflectionClass::FLOAT => 'float',
        DateTime::class => '\DateTime',
        Date::class => '\DateTime',
        GeoPoint::class => 'mixed',
        ValueObject::class => 'mixed',
    ];

    public const SQL_TYPES_TO_DEFAULT_INDEX_TYPE_ALLOCATIONS = [
        self::SQL_TYPE_INT => DatabaseIndex::TYPE_INDEX,
        self::SQL_TYPE_BIGINT => DatabaseIndex::TYPE_INDEX,
        self::SQL_TYPE_FLOAT => DatabaseIndex::TYPE_INDEX,
        self::SQL_TYPE_DOUBLE => DatabaseIndex::TYPE_INDEX,
        self::SQL_TYPE_BOOL => DatabaseIndex::TYPE_INDEX,
        self::SQL_TYPE_VARCHAR => DatabaseIndex::TYPE_INDEX,
        self::SQL_TYPE_DATE => DatabaseIndex::TYPE_INDEX,
        self::SQL_TYPE_DATETIME => DatabaseIndex::TYPE_INDEX,
        self::SQL_TYPE_TEXT => DatabaseIndex::TYPE_FULLTEXT,
        self::SQL_TYPE_MEDIUMTEXT => DatabaseIndex::TYPE_FULLTEXT,
        self::SQL_TYPE_LONGTEXT => DatabaseIndex::TYPE_FULLTEXT,
        self::SQL_TYPE_BLOB => DatabaseIndex::TYPE_NONE,
        self::SQL_TYPE_MEDIUMBLOB => DatabaseIndex::TYPE_NONE,
        self::SQL_TYPE_LONGBLOB => DatabaseIndex::TYPE_NONE,
        self::SQL_TYPE_JSON => DatabaseIndex::TYPE_NONE,
        self::SQL_TYPE_POINT => DatabaseIndex::TYPE_SPATIAL,
    ];

    public const SPATIAL_SQL_TYPES = [
        'point' => true
    ];

    /** @var string|null Name of the database Column */
    public string $name;

    /** @var string|null PHP data type of the column */
    public ?string $phpType;

    /** @var string|null SQL data type of the column */
    public ?string $sqlType;

    /** @var string|null PHP default value of the column */
    public mixed $phpDefaultValue;

    /** @var string|null SQL default value of the column */
    public mixed $sqlDefaultValue;

    /** @var bool Wheather the column is nullable or not */
    public ?bool $allowsNull = true;

    /** @var bool Wheather the column is a base type in php or not */
    public bool $isBuildinType = true;

    /** @var bool Wheather the column has to be autoincremented or not */
    public ?bool $hasAutoIncrement = false;

    /** @var bool In case of a number, determines if it is unsigned or not */
    public ?bool $isUnsigned = false;

    /** @var bool Wheather the column has an index or not */
    public bool $hasIndex = true;

    /** @var int If type is varchar, this is used for it's length */
    public ?int $varCharLength = 255;

    /** @var bool It true, the column will be encrypted in the Database using an encryption password from the Encrypt class */
    public bool $encrypted = false;

    /** @var bool It true, property is ignored and no Database column is generated */
    public bool $ignoreProperty = false;

    /** @var bool It true, columns is json and will be upserted with JSON_MERGE_PATCH */
    public bool $isMergableJSONColumn = false;

    /**
     * @var string * If set, on duplicate, the update action is applioed instead of {$column} = VALUES({$column})
     * Usefull e.g. if a counter has to be incremented on update
     * */
    public ?string $onUpdateAction;

    /** @var string If encryption is set, encryptionScope is required, it can be one of the scopes defined in EncryptionScope ø */
    #[Choice(callback: [EncryptionScope::class, 'getScopes'])]
    public ?string $enryptionScope;

    /** @var bool Wheather the column is primary key or not */
    public bool $isPrimaryKey = false;

    /** @var DatabaseVirtualColumns Database VirtualColumns based on current column */
    public DatabaseVirtualColumns $virtualColumnsBasedOnCurrentColumn;

    public static function createFromReflectionProperty(
        ReflectionClass $reflectionClass,
        ReflectionProperty $reflectionProperty
    ): ?DatabaseColumn {
        $databaseColum = new DatabaseColumn();

        /** @var LazyLoad $lazyloadAttributeInstance */
        // we ignore lazyloaded properties of type ClASS_METHOD
        if ($lazyloadAttribute = $reflectionProperty?->getAttributes(LazyLoad::class)[0] ?? null) {
            $lazyloadAttributeInstance = $lazyloadAttribute->newInstance();
            if ($lazyloadAttributeInstance->repoType == LazyLoadRepo::CLASS_METHOD) {
                return null;
            }
        }


        $type = $reflectionProperty->getType();
        $propertyName = $reflectionProperty->getName();
        $databaseColum->name = $propertyName;
        $databaseColum->isPrimaryKey = $propertyName == self::PRIMARY_KEY_NAME;
        $databaseColum->isUnsigned = false;
        $databaseColum->allowsNull = true;
        $databaseColum->varCharLength = 255;

        if ($databaseColum->isPrimaryKey) {
            $databaseColum->hasAutoIncrement = true;
            $databaseColum->isUnsigned = true;
        }

        if (isset(self::PROPERTIES_TO_SKIP[$propertyName])) {
            return null;
        }
        if ($type instanceof ReflectionUnionType) {
            $types = $type->getTypes();//[0];
            $allowsNull = false;
            foreach ($types as $unionType) {
                if ($unionType->getName() != 'null') {
                    $type = $unionType;
                } else {
                    $allowsNull = true;
                }
            }
            if (!$allowsNull && $type->allowsNull()) {
                $allowsNull = true;
            }
            $databaseColum->allowsNull = $allowsNull;
        }
        if (!$type instanceof ReflectionNamedType) {
            throw new InternalErrorException(
                "No type specified in {$reflectionClass->getName()}.{$propertyName}"
            );
        }
        $databaseColum->phpType = $type->getName();
        $databaseColum->isBuildinType = $type->isBuiltin();

        if ($databaseColum->isBuildinType) {
            $databaseColum->sqlType = self::SQL_TYPE_ALLOCATION[$type->getName()];
            if ($reflectionProperty->getDefaultValue() !== null) {
                $databaseColum->phpDefaultValue = $reflectionProperty->getDefaultValue();
                if ($type->getName() == ReflectionClass::BOOL) {
                    $databaseColum->sqlDefaultValue = (int)$reflectionProperty->getDefaultValue();
                } elseif ($type->getName() == ReflectionClass::INTEGER) {
                    $databaseColum->sqlDefaultValue = (int)$reflectionProperty->getDefaultValue();
                } elseif ($type->getName() == ReflectionClass::FLOAT) {
                    $databaseColum->sqlDefaultValue = (float)$reflectionProperty->getDefaultValue();
                } else {
                    $databaseColum->sqlDefaultValue = $reflectionProperty->getDefaultValue();
                }
            }
        }
        if (
            ($choicesAttribute = $reflectionProperty->getAttributes(
                Choice::class,
                ReflectionAttribute::IS_INSTANCEOF
            )[0] ?? null) && self::MAP_CHOICES_TO_ENUMS
        ) {
            /** @var Choice $choicesAttributeInstance */
            $choicesAttributeInstance = $choicesAttribute->newInstance();
            $databaseColum->sqlType = 'ENUM(' . implode(
                    ',',
                    array_map(function (string $el) {
                        return "'$el'";
                    }, $choicesAttributeInstance->choices)
                ) . ')';
        } // handle length limits
        elseif (
            $type->getName() == ReflectionClass::STRING && ($lengthAttribute = $reflectionProperty->getAttributes(
                Length::class
            )[0] ?? null)
        ) {
            /** @var Length $lengthAttributeInstance */
            $lengthAttributeInstance = $lengthAttribute->newInstance();
            if ($lengthAttributeInstance->max) {
                $databaseColum->varCharLength = $lengthAttributeInstance->max;
            }
        } elseif ($type->getName() == DateTime::class) {
            $databaseColum->sqlType = self::SQL_TYPE_ALLOCATION[DateTime::class];
        } elseif ($type->getName() == Date::class) {
            $databaseColum->sqlType = self::SQL_TYPE_ALLOCATION[Date::class];
        } elseif (is_a($type->getName(), GeoPoint::class, true)) {
            $databaseColum->sqlType = self::SQL_TYPE_ALLOCATION[GeoPoint::class];
        } elseif (is_a($type->getName(), ValueObject::class, true)) {
            // ignore Lazyload Repos ValueObject e.g. Virtual Repotype
            if ($lazyloadAttributes = $reflectionProperty->getAttributes(LazyLoad::class)) {
                foreach ($lazyloadAttributes as $lazyloadAttribute) {
                    /** @var LazyLoad $lazyloadAttributeInstance */
                    $lazyloadAttributeInstance = $lazyloadAttribute->newInstance();
                    if ($lazyloadAttributeInstance->repoType == LazyLoadRepo::VIRTUAL) {
                        return null;
                    }
                }
            }

            $databaseColum->sqlType = self::SQL_TYPE_ALLOCATION[ValueObject::class];
            $databaseColum->hasIndex = false;
        } elseif ($reflectionProperty->hasAttribute(Translatable::class)) {
            $databaseColum->sqlType = self::SQL_TYPE_ALLOCATION[ValueObject::class];
            $databaseColum->hasIndex = false;
            $databaseColum->phpType = ValueObject::class;
            $databaseColum->isMergableJSONColumn = true;
        } elseif (is_a($type->getName(), Entity::class, true)) {
            return null;
        }
        if (is_a($type->getName(), EntitySet::class, true)) {
            return null;
        }
        if ($propertyName == 'id') {
            $databaseColum->hasIndex = false;
            $databaseColum->allowsNull = false;
            $index = false;
        }

        // if DatabaseColumn attribute is present, we overwrite definitions from attribute
        if (
            $columnAttribute = $reflectionProperty->getAttributes(
                DatabaseColumn::class
            )[0] ?? null
        ) {
            /** @var DatabaseColumn $columnAttributeInstance */
            $columnAttributeInstance = $columnAttribute->newInstance();
            if ($columnAttributeInstance->sqlType !== null) {
                $databaseColum->sqlType = $columnAttributeInstance->sqlType;
            }
            $databaseColum->ignoreProperty = $columnAttributeInstance->ignoreProperty;

            if ($columnAttributeInstance->encrypted !== null && $columnAttributeInstance->encrypted) {
                $databaseColum->encrypted = $columnAttributeInstance->encrypted;
                if (
                    !isset($columnAttributeInstance->enryptionScope) || !in_array(
                        $columnAttributeInstance->enryptionScope,
                        EncryptionScope::getScopes()
                    )
                ) {
                    throw new InternalErrorException(
                        'DatabaseColumn ' . $reflectionProperty->getName() . ' in ' . $reflectionClass->getName(
                        ) . ' is defined as encrypted, but encryption scope is either wrong or missing'
                    );
                }
            }
            if ($columnAttributeInstance->allowsNull !== null) {
                $databaseColum->allowsNull = $columnAttributeInstance->allowsNull;
            }
            if ($columnAttributeInstance->hasAutoIncrement !== null) {
                $databaseColum->hasAutoIncrement = $columnAttributeInstance->hasAutoIncrement;
            }
            if ($columnAttributeInstance->isUnsigned !== null) {
                $databaseColum->isUnsigned = $columnAttributeInstance->isUnsigned;
            }
            if ($columnAttributeInstance->varCharLength !== null) {
                $databaseColum->varCharLength = $columnAttributeInstance->varCharLength;
            }
            if ($columnAttributeInstance->onUpdateAction !== null) {
                $databaseColum->onUpdateAction = $columnAttributeInstance->onUpdateAction;
            }
        }
        return $databaseColum;
    }

    /**
     * @return string Returns the doctrine column attribute type allocation used in doctrine models based on internal php type from Entity
     */
    public function getDoctrineColumnAttributeType(): string
    {
        if ($this->encrypted) {
            return self::DOCTRINE_SQL_TYPE_ALLOCATIONS[self::SQL_TYPE_TEXT];
        }
        return self::DOCTRINE_SQL_TYPE_ALLOCATIONS[$this->sqlType] ?? self::DOCTRINE_SQL_TYPE_ALLOCATIONS[self::SQL_TYPE_VARCHAR];
    }

    /**
     * @return string Returns the doctrine php property type allocation used in doctrine models based on internal php type from Entity
     */
    public function getDoctrinePhpType(): string
    {
        if ($this->encrypted) {
            return self::DOCTRINE_PHP_TYPE_ALLOCATIONS[ReflectionClass::STRING];
        }
        if ($this->isBuildinType || ($this->phpType == DateTime::class || $this->phpType == Date::class)) {
            return self::DOCTRINE_PHP_TYPE_ALLOCATIONS[$this->phpType];
        }
        if (is_a($this->phpType, ValueObject::class, true)) {
            return self::DOCTRINE_PHP_TYPE_ALLOCATIONS[ValueObject::class];
        }
        return self::DOCTRINE_PHP_TYPE_ALLOCATIONS[$this->phpType];
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name);
    }

    public function getSqlType(): string
    {
        if ($this->encrypted) {
            // in case of encrypted properties, we use varchar always, e.g. convert int to varchar
            if (
                !in_array(
                    $this->sqlType, [
                        self::SQL_TYPE_VARCHAR,
                        self::SQL_TYPE_TEXT,
                        self::SQL_TYPE_MEDIUMTEXT,
                        self::SQL_TYPE_LONGTEXT,
                        self::SQL_TYPE_JSON
                    ]
                )
            ) {
                return self::SQL_TYPE_VARCHAR . '(255)';
            } // JSON is treated as text, as on encryption JSON is not persisted
            elseif ($this->sqlType == self::SQL_TYPE_JSON) {
                return self::SQL_TYPE_TEXT;
            }
        }
        if ($this->sqlType == self::SQL_TYPE_VARCHAR) {
            return self::SQL_TYPE_VARCHAR . "({$this->varCharLength})";
        }
        $unsigned = in_array($this->sqlType, [self::SQL_TYPE_INT, self::SQL_TYPE_BIGINT]
        ) && $this->isUnsigned ? ' UNSIGNED' : '';
        return $this->sqlType . $unsigned;
    }

    public function getSql(bool $asUpdate = false): ?string
    {
        if ($this->ignoreProperty) {
            return null;
        }
        $sql = $asUpdate ? 'ADD COLUMN IF NOT EXISTS ' : '';
        $defaultValue = '';
        $defaultValueSet = false;

        if (isset($this->sqlDefaultValue)) {
            $defaultValueSet = true;
            if (is_string($this->sqlDefaultValue)) {
                $defaultValue = "'{$this->sqlDefaultValue}'";
            } else {
                $defaultValue = $this->sqlDefaultValue;
            }
        } elseif ($this->allowsNull) {
            $defaultValue = 'NULL';
            $defaultValueSet = true;
        }

        $sql .= '`' . $this->name . '` ' . $this->getSqlType(
            ) . (!$this->allowsNull ? ' NOT NULL' : '') . ($this->hasAutoIncrement ? ' AUTO_INCREMENT' : '') . ($defaultValueSet ? ' DEFAULT ' . $defaultValue : '');
        return $sql;
    }

    public function getPhpDefaultValueAsString(): string
    {
        if (!isset($this->phpDefaultValue)) {
            return '';
        }
        if (is_bool($this->phpDefaultValue)) {
            return $this->phpDefaultValue ? 'true' : 'false';
        }
        if (is_string($this->phpDefaultValue)) {
            return "'{$this->phpDefaultValue}'";
        }
        return (string)$this->phpDefaultValue;
    }

    public function __construct(
        string $sqlType = null,
        bool $allowsNull = null,
        bool $hasAutoIncrement = null,
        bool $isUnsigned = null,
        int $varCharLength = null,
        bool $encrypted = false,
        ?string $encryptionScope = null,
        ?string $onUpdateAction = null,
        bool $ignoreProperty = false,
        bool $isMergableJSONColumn = false,
    ) {
        $this->sqlType = $sqlType;
        $this->allowsNull = $allowsNull;
        $this->hasAutoIncrement = $hasAutoIncrement;
        $this->isUnsigned = $isUnsigned;
        $this->varCharLength = $varCharLength;
        $this->encrypted = $encrypted;
        $this->onUpdateAction = $onUpdateAction;
        $this->enryptionScope = $encryptionScope;
        $this->ignoreProperty = $ignoreProperty;
        $this->isMergableJSONColumn = $isMergableJSONColumn;
        parent::__construct();
    }
}