<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Virtual;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\Lazyload\LazyLoad;

class VirtualEntity
{
    /**
     * This method calls the
     * @param bool $useEntityRegistryCache
     * @return Entity|null
     */
    public function callLazyLoadMethod(
        string $lazyLoadMethod,
        DefaultObject &$initiatingEntity,
        LazyLoad &$lazyloadAttributeInstance
    ): ?DefaultObject {
        $virtualEntityRegistry = VirtualEntityRegistry::getInstance();
        if ($lazyloadAttributeInstance->useCache) {
            $entityInstance = $virtualEntityRegistry->get(
                $initiatingEntity,
                static::class,
                $lazyLoadMethod,
                $lazyloadAttributeInstance
            );
            if ($entityInstance) {
                return $this->postProcessResult($entityInstance);
            }
        }
        $resultEntity = $this->$lazyLoadMethod($initiatingEntity, $lazyloadAttributeInstance);
        $virtualEntityRegistry->add($initiatingEntity, $resultEntity, static::class, $lazyLoadMethod);
        return $this->postProcessResult($resultEntity);
    }

    /**
     * This method allows to post process the result, this is especially usefull if the result should be modified
     * after it is stored to VirtualEntityRegistry, example:
     * ProjectSetting contains also a ProjectRightsSetting that depends on current Account and therefor cannot be stored
     * as logging into a project with a different account alters the rights
     * @param DefaultObject $result
     * @return DefaultObject|null
     */
    public function postProcessResult(DefaultObject &$result): ?DefaultObject
    {
        return $result;
    }
}