<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\CacheScopes\Invalidations;

use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\CacheScopes\CacheScope;
use DDD\Domain\Common\Repo\DB\CacheScopes\Invalidations\DBCacheScopeInvalidation;
use DDD\Domain\Common\Services\CacheScopeInvalidationsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Represents an invalidation of a cache scope that needs to be executed on the access of the particular cache scope
 * This permits e.g. invaliation of FeatureFlags cache after a subscription upgrade
 * It can be used in a way to invalidate the particular CacheScope for a number of times or until a particular time is exceeded
 * The simplest case is to invalidate the CacheScope a single time
 * @method static CacheScopeInvalidationsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBCacheScopeInvalidation::class)]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['cacheScope', 'accountId'])]
class CacheScopeInvalidation extends Entity
{
    use ChangeHistoryTrait;

    /** @var string The name of the CacheScope */
    #[Choice(callback: [CacheScope::class, 'getCacheScopes'])]
    #[NotNull]
    public string $cacheScope;

    /** @var int The id of the Account for which the cache scope needs to be invalidated */
    public ?int $accountId;

    /** @var Account The Account for which the cache scope needs to be invalidated */
    #[LazyLoad(LazyLoadRepo::DB)]
    public Account $account;

    /** @var int If set, this counter is decremented each time the cacheScope is invalidated */
    public ?int $numberOfTimesToInvalidateCache;

    /** @var DateTime If set, the cache scope is invalidated until current time exceeded this date time */
    public ?DateTime $invalidateUntil;


    /**
     * we overwrite uniqueKey here since we want to have unique names
     * @return string
     */
    public function uniqueKey(): string
    {
        $key = '';
        if (isset($this->id)) {
            $key .= $this->id;
        }
        $key .= $this->cacheScope . '_' . ($this->accountId ?? '');
        if (isset($this->invalidateUntil)) {
            $key .= $this->invalidateUntil;
        }
        return self::uniqueKeyStatic($key);
    }

    /**
     * @return bool Returns true, if CacheScopeInvalidation did not expire maxium numberOfTimesToInvalidateCache have not been used up yet
     */
    public function isApplicable(): bool
    {
        if (isset($this->numberOfTimesToInvalidateCache) && $this->numberOfTimesToInvalidateCache) {
            return true;
        }
        if (isset($this->invalidateUntil) && $this->invalidateUntil->getTimestamp() > time()) {
            return true;
        }
        return false;
    }

    /**
     * Applies a CacheScopeInvalidation and reduces numberOfTimesToInvalidateCache if set
     * @return $this|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function applyInvalidation(): ?CacheScopeInvalidation
    {
        if ($this->numberOfTimesToInvalidateCache ?? null) {
            $this->numberOfTimesToInvalidateCache--;
            $this->update();
        }
        if (!$this->isApplicable()) {
            $this->delete();
        }
        return $this;
    }
}