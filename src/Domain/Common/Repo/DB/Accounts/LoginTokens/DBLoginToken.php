<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\Accounts\LoginTokens;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Entities\Accounts\LoginTokens\LoginToken;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Exception;
use ReflectionException;

/**
 * @method LoginToken find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @property DBLoginTokenModel $ormInstance
 */
class DBLoginToken extends DBEntity
{
    public const string BASE_ENTITY_CLASS = LoginToken::class;
    public const string BASE_ORM_MODEL = DBLoginTokenModel::class;

    /**
     * Deletes expired Tokens
     * @return void
     * @throws Exception
     * @throws ReflectionException
     */
    public function deleteExpiredTokens(): void
    {
        // DQL, not raw SQL: the identifiers are quoted per engine (PostgreSQL folds unquoted mixed case to lower case)
        EntityManagerFactory::getInstance()->createQueryBuilder()
            ->delete(static::BASE_ORM_MODEL, 'loginToken')
            ->where('loginToken.usageLimit <= 0 OR loginToken.validUntil < :currentDate')
            ->setParameter('currentDate', new DateTime('now'), Types::DATETIME_MUTABLE)
            ->getQuery()
            ->execute();
    }
}