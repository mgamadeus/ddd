<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\ORM\NonUniqueResultException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @method static Account getEntityClassInstance()
 */
class AccountsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = Account::class;

    public function __construct(public ?RequestStack $requestStack = null) {}

    /**
     * @param string|int|null $accountId
     * @return Account|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws NotFoundException
     * @throws ReflectionException
     */
    public function find(int|string|null $entityId, bool $useEntityRegistrCache = true): ?Account
    {
        if (!$entityId) {
            $accountId = AuthService::instance()->getAccount()->id;
        }
        /** @var Account $entityClass */
        $entityClass = DDDService::instance()->getContainerServiceClassNameForClass(
            (string)static::DEFAULT_ENTITY_CLASS
        );
        $repoClassInstance = $entityClass::getRepoClassInstance();

        $account = $repoClassInstance->find($entityId);
        if (!$account) {
            if ($this->throwErrors) {
                throw new NotFoundException('Account not found or current Auth Account is not authorized to access it');
            }
            return null;
        }

        // we reset Query options and search again
        $defaultQueryOptions = $entityClass::getDefaultQueryOptions();
        $account->setQueryOptions($defaultQueryOptions);
        // as the can be already loaded, it is not considering newer query options eventually, therefor we apply query options here.
        if ($this->throwErrors && !$account) {
            throw new NotFoundException('Account not found or current Auth Account is not authorized to access it');
        }
        return $account;
    }

    /**
     * Find account by email
     * @param string $email
     * @return Account|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws NonUniqueResultException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findByEmail(string $email): ?Account
    {
        $entityClassInstance = self::getEntityClassInstance();
        $repoClassInstance = self::getEntityClassInstance()::getRepoClassInstance();
        $queryBuilder = $repoClassInstance::createQueryBuilder();
        $alias = $repoClassInstance::getBaseModelAlias();
        $queryBuilder->where("{$alias}.email = :email")->setParameter('email', $email);
        $account = $repoClassInstance->find($queryBuilder);
        if ($this->throwErrors && !$account) {
            throw new NotFoundException('Account not found or current Auth Account is not authorized to access it');
        }
        return $account;
    }

    /**
     * Get currently authenticated account
     * @return void
     * @throws NotFoundException
     */
    public function getAuthAccount(): ?Account
    {
        /** @var AuthService $auth */
        $account = AuthService::instance()->getAccount();
        if ($this->throwErrors && !isset($account)) {
            throw new NotFoundException('Account not found or current Auth Account is not authorized to access it');
        }

        return $account;
    }

}