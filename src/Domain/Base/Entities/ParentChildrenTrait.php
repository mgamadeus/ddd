<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

use DDD\Infrastructure\Traits\Serializer\Attributes\HidePropertyOnSystemSerialization;
use stdClass;

trait ParentChildrenTrait
{
    #[HidePropertyOnSystemSerialization]
    protected ?DefaultObject $parent = null;

    #[HidePropertyOnSystemSerialization]
    protected ?ChildrenSet $children = null;

    /**
     * Ads Elements to children of this Instance
     * @param DefaultObject|null $child
     * @return void
     */
    public function addChildren(?DefaultObject &...$children): void
    {
        if (!$this->children) {
            $this->children = new ChildrenSet();
            $this->children->setAddAsChild(false);
        }
        foreach ($children as $child) {
            if (!$child) {
                continue;
            }
            $child->setParent($this);
        }
        // this must come in this order, as sometimes the child relies on his parent for uniqueId and add uses uniqueId
        $this->children->add(...$children);
    }

    public function removeChildren(DefaultObject &...$children): void
    {
        $this->children->remove(...$children);
    }

    public function getParent(): ?DefaultObject
    {
        return $this->parent;
    }

    public function hasObjectInParents(object &$parentObject, array $callPath = []): bool
    {
        if (isset($callPath[spl_object_id($this)])) {
            return false;
        }
        $callPath[spl_object_id($this)] = true;
        if (!$this->getParent()) {
            return false;
        }
        if ($this->getParent() === $parentObject) {
            return true;
        }
        if (method_exists($this->getParent(), 'hasObjectInParents')) {
            return $this->getParent()->hasObjectInParents($parentObject, $callPath);
        }
        return false;
    }

    /**
     * Sets parent of this Instance
     * @param DefaultObject $parent
     * @return void
     */
    public function setParent(DefaultObject &$parent)
    {
        $this->parent = $parent;
    }

    /**
     * debug function that returns structure of objects and their children
     * @return array|stdClass
     */
    public function getObjectStructure(): array|stdClass
    {
        $resultObject = [];
        $resultObject['objectType'] = static::class;
        if (method_exists($this, 'uniqueKey')) {
            $resultObject['uniqueKey'] = $this->uniqueKey();
        }
        if (isset($this->argusSettings) && $this->argusSettings) {
            $resultObject['argusLoad'] = $this->argusSettings;
        }
        if (method_exists($this, 'getChildren')) {
            $resultObject['children'] = [];
            foreach ($this->getChildren() as $child) {
                $resultObject['children'][] = $child->getObjectStructure();
            }
        }
        return $resultObject;
    }

    /**
     * returns Children ObjectSet
     * @return DefaultObject[]
     */
    public function getChildren(): ?array
    {
        if (!$this->children) {
            return [];
        }
        return $this->children->elements;
    }
}