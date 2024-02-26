<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\ChangeHistory;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;

#[Attribute(Attribute::TARGET_CLASS)]
class ChangeHistory extends ValueObject
{
    use BaseAttributeTrait;

    public const TIMESTAMP = 'timestamp';
    public const DATETIME_ATOM = 'datetime_atom';
    public const DATETIME_SIMPLE = 'datetime_simple';

    public const DEFAULT_CREATED_COLUMN_NAME = 'created';
    public const DEFAULT_MODIFIED_COLUMN_NAME = 'updated';
    public const DEFAULT_COLUMN_STYLE = self::DATETIME_SIMPLE;

    protected ?string $createdColumn = self::DEFAULT_CREATED_COLUMN_NAME;
    protected ?string $modifiedColumn = self::DEFAULT_MODIFIED_COLUMN_NAME;

    protected ?string $createdColumnStyle;
    protected ?string $modifiedColumnStyle;

    /**
     * The time at which the entity has been created and persisted
     * @var DateTime|null
     */
    public ?DateTime $createdTime;

    /**
     * The time at which the entity has been modified and persisted
     * @var DateTime|null
     */
    public ?DateTime $modifiedTime;

    /** @var bool If true, the created and modified time is overwritten if present on entity, usefull e.g. in case of migrations */
    #[HideProperty]
    public bool $overwriteCreatedAndModifiedTime = false;

    public function __construct(
        string $createdColumn = self::DEFAULT_CREATED_COLUMN_NAME,
        string $modifiedColumn = self::DEFAULT_MODIFIED_COLUMN_NAME,
        string $createdColumnStyle = self::TIMESTAMP,
        string $modifiedColumnStyle = self::TIMESTAMP
    ) {
        $this->createdColumn = $createdColumn;
        $this->modifiedColumn = $modifiedColumn;
        $this->createdColumnStyle = $createdColumnStyle;
        $this->modifiedColumnStyle = $modifiedColumnStyle;
    }

    /**
     * @return void Sets operation mode for EntityDB, so that defaults for created and modified columns are changed
     */
    public function setEntityOperationMode()
    {
        $this->createdColumnStyle = self::DATETIME_SIMPLE;
        $this->modifiedColumnStyle = self::DATETIME_SIMPLE;
    }

    /**
     * @return string|null
     */
    public function getCreatedColumn(): ?string
    {
        return $this->createdColumn;
    }

    /**
     * @return string|null
     */
    public function getModifiedColumn(): ?string
    {
        return $this->modifiedColumn;
    }

    /**
     * @return string|null
     */
    public function getCreatedColumnStyle(): ?string
    {
        return $this->createdColumnStyle;
    }

    /**
     * @return string|null
     */
    public function getModifiedColumnStyle(): ?string
    {
        return $this->modifiedColumnStyle;
    }


}