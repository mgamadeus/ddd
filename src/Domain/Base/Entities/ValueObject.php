<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities;

use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Arr;
use ReflectionException;

class ValueObject extends DefaultObject
{
    public function __construct()
    {
        parent::__construct();
    }

    public function equals(?BaseObject &$other = null): bool
    {
        if (!$other) {
            return false;
        }
        if (!($other instanceof ValueObject)) {
            return false;
        }
        return $this->uniqueKey() == $other->uniqueKey();
    }

    /**
     * creates a unique key by using the content of all public properties.
     * the idea behing the uniqueKey of two ValueObjects is that if all properties are equal
     * and the ValueObject Class is equal, then their uniqueKey's are identical too.
     * @return string
     */
    public function uniqueKey(): string
    {
        //$contentHash = md5(json_encode($this->toObject(true, true)));
        $contentHash = spl_object_id($this);
        return self::uniqueKeyStatic($contentHash);
    }

    /**
     * @return mixed This method transforms the data to a persistence format. By default JSON is used
     * but in some cases a special format can make sense
     */
    public function mapToRepository(): mixed
    {
        return $this->toObject(ignoreHideAttributes: true, cached: false, forPersistence: true);
    }

    /**
     * This is the inverse method to mapToRepository, the object populates itself with the data from the repo
     * @param mixed $repoObject
     * @return void
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function mapFromRepository(mixed $repoObject): void
    {
        if (is_string($repoObject)) {
            $repoObjectProcessed = json_decode($repoObject);
        } elseif (is_array($repoObject)) {
            $repoObjectProcessed = Arr::toObject($repoObject);
        } elseif (is_object($repoObject)) {
            $repoObjectProcessed = $repoObject;
        }
        $this->setPropertiesFromObject($repoObjectProcessed, false);
    }
}