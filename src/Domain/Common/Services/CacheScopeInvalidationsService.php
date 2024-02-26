<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Common\Entities\CacheScopes\Invalidations\CacheScopeInvalidation;
use DDD\Domain\Common\Entities\CacheScopes\Invalidations\CacheScopeInvalidations;
use DDD\Domain\Common\Repo\DB\CacheScopes\Invalidations\DBCacheScopeInvalidation;
use DDD\Domain\Common\Repo\DB\CacheScopes\Invalidations\DBCacheScopeInvalidations;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Interfaces\AccountDependentEntityInterface;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

class CacheScopeInvalidationsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = CacheScopeInvalidation::class;

    /** @var CacheScopeInvalidations[] CacheScopeInvalidations by accountId */
    private static array $cacheScopeInvalidationsForAccounts = [];

    /** @var CacheScopeInvalidations[] CacheScopeInvalidations by projectId */
    private static array $cacheScopeInvalidationsForProjects = [];

    /** @var CacheScopeInvalidations[] CacheScopeInvalidations by locationId */
    private static array $cacheScopeInvalidationsForLocations = [];

    /**
     * Determines if given Lazyload initiating Entity implements one of
     * - LocationDependentEntityInterface
     * - ProjectDependentEntityInterface
     * - AccountDependentEntityInterface
     * and if so, uses corresponding id and scopes to determine if a CacheScopeInvalidation exists.
     * If one exists, returns false, else true.
     * @param array $cacheScopes
     * @param DefaultObject $initiatingEntity
     * @return bool
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public static function canUseCachingForScopesAndLazyloadInitiatingEntity(
        array $cacheScopes,
        DefaultObject &$initiatingEntity
    ): bool {
        $accountId = null;
        if ($initiatingEntity instanceof AccountDependentEntityInterface) {
            $accountId = $initiatingEntity->getAccount()->id;
        }
        if (!$accountId) {
            return true;
        }
        return self::canUseCachingForScopes(cacheScopes: $cacheScopes, accountId: $accountId);
    }

    /**
     * Based on static and on demand loaded CacheScopeInvalidations determines if caching is allowed for goven CacheScopes and parameters
     * @param array $cacheScopes
     * @param int|null $accountId
     * @return bool
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public static function canUseCachingForScopes(
        array $cacheScopes,
        ?int $accountId = null,
    ): bool {
        $cacheScopeInvalidation = null;
        if ($accountId) {
            if (!isset(self::$cacheScopeInvalidationsForAccounts[$accountId])) {
                self::$cacheScopeInvalidationsForAccounts[$accountId] = self::getCacheScopeInvalidations(
                    accountId: $accountId
                );
            }
            /** @var CacheScopeInvalidations $cacheScopeInvalidationsForAccount */
            $cacheScopeInvalidationsForAccount = self::$cacheScopeInvalidationsForAccounts[$accountId];
            $cacheScopeInvalidation = $cacheScopeInvalidationsForAccount->getCacheScopeInvalidationbyParameters(
                cacheScopes: $cacheScopes,
                accountId: $accountId
            );
        }
        if ($cacheScopeInvalidation) {
            $applicable = $cacheScopeInvalidation->isApplicable();
            if ($applicable) {
                $cacheScopeInvalidation->applyInvalidation();
                return false;
            }
        }
        return true;
    }

    /**
     * Returns all CacheScopeInvalidations for account / project / location
     * @param int|null $accountId
     * @return CacheScopeInvalidations
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public static function getCacheScopeInvalidations(
        ?int $accountId = null,
    ): CacheScopeInvalidations {
        $dbCacheScopeInvalidations = new DBCacheScopeInvalidations();
        $queryBuilder = $dbCacheScopeInvalidations::createQueryBuilder();
        $baseAlias = $dbCacheScopeInvalidations::getBaseModelAlias();
        $accountId = $accountId ?? 0;
        $queryBuilder->where(
            "
            (($baseAlias.numberOfTimesToInvalidateCache IS NULL or $baseAlias.numberOfTimesToInvalidateCache > 0)
            AND ($baseAlias.invalidateUntil IS NULL or $baseAlias.invalidateUntil > :currentDate))"
        )->setParameter('currentDate', (string)(new DateTime()));
        if ($accountId) {
            $queryBuilder->andWhere("$baseAlias.accountId = :accountId")
                ->setParameter('accountId', $accountId);
        }
        return $dbCacheScopeInvalidations->find($queryBuilder);
    }

    /**
     * Creates a CacheScopeInvalidation based on parameters
     * @param string $cacheScope
     * @param int|null $accountId
     * @param DateTime|null $invalidateUntil
     * @param int|null $numberOfTimesToInvalidateCache
     * @return CacheScopeInvalidation|null
     * @throws BadRequestException
     * @throws Exception
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     * @throws NotFoundException
     */
    public function createCacheScopeInvalidationFromparameters(
        string $cacheScope,
        ?int $accountId = null,
        ?DateTime $invalidateUntil = null,
        ?int $numberOfTimesToInvalidateCache = null
    ): ?CacheScopeInvalidation {
        $dbCacheScopeInvalidation = new DBCacheScopeInvalidation();
        if ($accountId) {
            $accountService = new AccountsService();
            $accountService->throwErrors = $this->throwErrors;
            $account = $accountService->find($accountId);
            if (!$account) {
                return null;
            }
        }
        $cacheScopeInvalidation = new CacheScopeInvalidation();
        $cacheScopeInvalidation->accountId = $accountId ?? null;
        $cacheScopeInvalidation->invalidateUntil = $invalidateUntil ?? null;
        $cacheScopeInvalidation->cacheScope = $cacheScope;
        $cacheScopeInvalidation->numberOfTimesToInvalidateCache = $numberOfTimesToInvalidateCache ?? null;
        $updatedCacheScopeInvalidation = $dbCacheScopeInvalidation->update($cacheScopeInvalidation);
        $this->deleteExpiredCacheScopeInvalidations();
        return $updatedCacheScopeInvalidation;
    }

    /**
     * * Creates a CacheScopeInvalidation
     * @param CacheScopeInvalidation|null $cacheScopeInvalidation
     * @return CacheScopeInvalidation|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function createCacheScopeInvalidation(
        CacheScopeInvalidation &$cacheScopeInvalidation,
    ): ?CacheScopeInvalidation {
        return $this->createCacheScopeInvalidationFromparameters(
            cacheScope: $cacheScopeInvalidation->cacheScope,
            accountId: $cacheScopeInvalidation->accountId ?? null,
            invalidateUntil: $cacheScopeInvalidation->invalidateUntil ?? null,
            numberOfTimesToInvalidateCache: $cacheScopeInvalidation->numberOfTimesToInvalidateCache ?? null
        );
    }


    /**
     * Deletes expired CacheScopeInvalidations, if $randomly is true executes on average every 600ths call
     * @param bool $randomly
     * @return void
     * @throws ReflectionException
     * @throws Exception
     */
    public function deleteExpiredCacheScopeInvalidations(bool $randomly = true): void
    {
        if ($randomly && mt_rand(0, 599) != 0) {
            return;
        }
        $dbCacheScopeInvalidations = new DBCacheScopeInvalidation();
        $dbCacheScopeInvalidations->deleteExpiredCacheScopeInvalidations();
    }
}