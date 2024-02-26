<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\ChangeHistory;

use DDD\Domain\Base\Entities\Entity;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Reflection\ReflectionClass;
use ReflectionException;

trait ChangeHistoryTrait
{
    /** @var ChangeHistory Change history Attribute instance */
    public ?ChangeHistory $changeHistory;

    /**
     * @return ChangeHistory Returns ChangeHistory and creates it implicitly if not present
     */
    public function getChangeHistory(): ChangeHistory
    {
        if (isset($this->changeHistory)) {
            return $this->changeHistory;
        }
        $changeHistoryAttribute = self::getChangeHistoryAttribute();
        $this->changeHistory = $changeHistoryAttribute;
        return $this->changeHistory;
    }

    /**
     * Returns instantiated Change History attribute and checks also parent Entity classes, if necessary
     * @param bool $entityOperationMode
     * @return ChangeHistory|null
     * @throws ReflectionException
     */
    public static function getChangeHistoryAttribute(bool $entityOperationMode = true): ?ChangeHistory
    {
        if ($entityOperationMode) {
            if (!is_a(static::class, Entity::class, true)) {
                return null;
            }
            $parentEntityClassName = static::getParentEntityClassName();
            $reflectionClass = ReflectionClass::instance($parentEntityClassName ?? static::class);
        } else {
            $reflectionClass = ReflectionClass::instance(static::class);
        }
        $changeHistoryAttribute = $reflectionClass->getAttributeInstance(ChangeHistory::class);
        if (!$changeHistoryAttribute) {
            $changeHistoryAttribute = new ChangeHistory();
        }
        if ($entityOperationMode) {
            $changeHistoryAttribute->setEntityOperationMode();
        }
        return $changeHistoryAttribute;
    }

    /**
     * @return DateTime|null Returns creation time
     */
    public function getCreatedTime(): ?DateTime
    {
        if (!($this->changeHistory ?? null)) {
            return null;
        }
        return $this->changeHistory->createdTime ?? null;
    }

    /**
     * @return DateTime|null Returns modified time
     */
    public function getModifiedTime(): ?DateTime
    {
        if (!($this->changeHistory ?? null)) {
            return null;
        }
        return $this->changeHistory->modifiedTime ?? null;
    }

    /**
     * @return DateTime|null Returns either modifiedTime or created time if no modified time is present
     */
    public function getLastEditTime(): ?DateTime
    {
        if ($modifiedTime = $this->getModifiedTime()) {
            return $modifiedTime;
        }
        if ($createdTime = $this->getCreatedTime()) {
            return $createdTime;
        }
        return null;
    }
}