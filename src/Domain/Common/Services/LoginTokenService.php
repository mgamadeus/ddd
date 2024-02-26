<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Accounts\LoginTokens\LoginToken;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\AppService;
use DDD\Infrastructure\Services\AuthService;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

class LoginTokenService extends EntitiesService
{
    /**
     * Creates a login token
     * @param Account|null $account
     * @param DateTime|null $validUntil
     * @param int|null $usageLimit
     * @param int|null $lifeTimeInSeconds
     * @return LoginToken|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws ORMException
     * @throws NonUniqueResultException
     * @throws OptimisticLockException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function createLoginToken(
        ?Account &$account = null,
        ?DateTime $validUntil = null,
        ?int $usageLimit = null,
        ?int $lifeTimeInSeconds = null
    ): ?LoginToken {
        if (!$account) {
            $account = AuthService::instance()->getAccount();
        }
        if (!$account) {
            return null;
        }
        $loginToken = new LoginToken();
        $loginToken->accountId = $account->id;
        $loginToken->validUntil = $validUntil;
        $loginToken->usageLimit = $usageLimit;
        $loginToken->token = hash('sha256', bin2hex(random_bytes(32)));
        if ($lifeTimeInSeconds && !$validUntil) {
            $loginToken->validUntil = DateTime::fromTimestamp(time() + $lifeTimeInSeconds);
        }
        $updatedToken = $loginToken->update();
        $this->deleteExpiredTokens();
        return $updatedToken;
    }

    /**
     * Returns an Account based on a login Token
     * @param string $token
     * @return Account|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws ReflectionException
     */
    public function getAccountIdFromToken(
        string $token,
        bool $loginAccount = true,
    ): ?int {
        $dbLoginToken = LoginToken::getRepoClassInstance();

        AppService::instance()->deactivateEntityRightsRestrictions();
        $queryBuilder = $dbLoginToken::createQueryBuilder();
        $queryBuilder->where($dbLoginToken::getBaseModelAlias() . '.token = :token')
            ->setParameter('token', $token);
        $loginToken = $dbLoginToken->find($queryBuilder);
        AppService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        if (!$loginToken) {
            return null;
        }
        if (isset($loginToken->usageLimit)) {
            if ($loginToken->usageLimit <= 0) {
                $dbLoginToken->delete($loginToken);
                return null;
            }
            $loginToken->usageLimit--;
            $dbLoginToken->update($loginToken);
        }
        if (isset($loginToken->validUntil) && $loginToken->validUntil->getTimestamp() < time()) {
            $dbLoginToken->delete($loginToken);
            return null;
        }
        if ($loginAccount) {
            AuthService::instance()->setAccountId($loginToken->accountId);
        }
        return $loginToken->accountId;
    }

    /**
     * Deletes expired tokens, if $randomly is true executes on average every 600ths call
     * @param bool $randomly
     * @return void
     * @throws ReflectionException
     * @throws Exception
     */
    public function deleteExpiredTokens(bool $randomly = true): void
    {
        if ($randomly && mt_rand(0, 599) != 0) {
            return;
        }
        $dbLoginToken = LoginToken::getRepoClassInstance();
        $dbLoginToken->deleteExpiredTokens();
    }
}