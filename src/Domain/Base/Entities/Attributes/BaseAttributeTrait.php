<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\Attributes;

trait BaseAttributeTrait
{
    /** @var string Class Name, the Attribute has been atteched to */
    protected string $classNameAttributeIsAttachedTo;

    /** @var string Property name the Attribute has been attached to */
    protected string $propertyNameAttributeIsAttachedTo;

    /**
     * @return string
     */
    public function getClassNameAttributeIsAttachedTo(): string
    {
        return $this->classNameAttributeIsAttachedTo;
    }

    /**
     * @param string $classNameAttributeIsAttachedTo
     */
    public function setClassNameAttributeIsAttachedTo(string $classNameAttributeIsAttachedTo): void
    {
        $this->classNameAttributeIsAttachedTo = $classNameAttributeIsAttachedTo;
    }

    /**
     * @return string
     */
    public function getPropertyNameAttributeIsAttachedTo(): string
    {
        return $this->propertyNameAttributeIsAttachedTo;
    }

    /**
     * @param string $propertyNameAttributeIsAttachedTo
     */
    public function setPropertyNameAttributeIsAttachedTo(string $propertyNameAttributeIsAttachedTo): void
    {
        $this->propertyNameAttributeIsAttachedTo = $propertyNameAttributeIsAttachedTo;
    }

}
