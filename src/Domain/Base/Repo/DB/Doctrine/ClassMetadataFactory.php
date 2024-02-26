<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Extended ClassMetadataFactory to use custom ClassMetadata class
 */
class ClassMetadataFactory extends \Doctrine\ORM\Mapping\ClassMetadataFactory
{

    protected function newClassMetadataInstance($className)
    {
        return new ClassMetadata(
            $className,
            $this->getEm()->getConfiguration()->getNamingStrategy(),
            $this->getEm()->getConfiguration()->getTypedFieldMapper()
        );
    }

    public function getEm():EntityManagerInterface|null
    {
        $reflectionClass = new \ReflectionClass(\Doctrine\ORM\Mapping\ClassMetadataFactory::class);
        $reflectionProperty = $reflectionClass->getProperty('em');
        $reflectionProperty->setAccessible(true);
        return $reflectionProperty->getValue($this);
    }
}


