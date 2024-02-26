<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Accounts;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * @method Account find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method Account update(Entity &$entity, int $depth = 1)
 * @property DBAccount $ormInstance
 */
class DBAccount extends DBEntity
{
    public const BASE_ENTITY_CLASS = Account::class;
    public const BASE_ORM_MODEL = DBAccount::class;

    /**
     * @param string $email
     * @return Account|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findByEmail(string $email): ?Account
    {
        $queryBuilder = static::createQueryBuilder();
        $alias = self::getBaseModelAlias();
        $queryBuilder->where("{$alias}.email = :email")->setParameter('email', $email);
        return $this->find($queryBuilder);
    }
}