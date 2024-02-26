<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Services;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AppService;
use DDD\Infrastructure\Libs\AuthRedis;
use DDD\Infrastructure\Libs\Config;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Cache\InvalidArgumentException;
use Throwable;

class AuthService
{
    protected static ?Account $account = null;
    protected static ?Account $adminAccount = null;

    /** @var AuthService */
    protected static $instance;
    
    public static function instance(): self
    {
        if (isset(self::$instance)) {
            return self::$instance;
        }
        /** @var self $instance */
        $instance = AppService::instance()->getService(self::class);
        self::$instance = $instance;
        return $instance;
    }

    /**
     * @return Account|null
     */
    public function getAccount(): ?Account
    {
        return self::$account;
    }

    /**
     * @param Account|null $account
     * @return void
     */
    public function setAccount(?Account &$account): void
    {
        self::$account = $account;
    }

    /**
     * @param Account $account
     */
    public function setAccountId(int &$accountId): void
    {
        AppService::instance()->deactivateEntityRightsRestrictions();
        $account = Account::getService()->find($accountId);
        $this->setAccount($account);
        AppService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    /**
     * @return Account
     */
    public function getAdminAccount(): Account
    {
        return self::$adminAccount;
    }

    /**
     * @param Account $adminAccount
     */
    public function setAdminAccount(Account &$adminAccount): void
    {
        self::$adminAccount = $adminAccount;
    }

    public function isLoggedIn(): bool
    {
        return isset(self::$account);
    }

    /**
     * @param string|null $authToken
     * @param string|null $authTokenType
     * @return bool
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function checkLogin(?string $authToken = null, ?string $authTokenType = null): bool
    {
        $tokenTypeJwt = Config::getEnv('AUTH_JWT_HASH_TOKEN_TYPE_JWT');
        $tokenTypeBasic = Config::getEnv('AUTH_JWT_HASH_TOKEN_TYPE_BASIC');
        return match ($authTokenType) {
            $tokenTypeJwt => $this->validateJwt($authToken),
            $tokenTypeBasic => $this->validateBasicAuthToken($authToken),
            default => $this->checkRedisAuthLogin()
        };
    }

    /**
     * @param string|null $jwt
     * @return bool
     * @throws InvalidArgumentException
     */
    public function validateJwt(?string $jwt): bool
    {
        if (!$jwt) {
            return false;
        }
        try {
            $decoded = JWT::decode($jwt, new Key(Config::getEnv('AUTH_JWT_HASH_KEY'), Config::getEnv('AUTH_JWT_HASH_METHOD')));

            if ($decoded->user_id) {
                AppService::instance()->deactivateEntityRightsRestrictions();
                $account = Account::byId($decoded->user_id);
                AppService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
                self::setAccount($account);
            }
        } catch (Exception $e) {
            return false;
        }
        return true;
    }


    /**
     * Checks login state based on session cookie
     * @return bool
     */
    public function checkRedisAuthLogin(): bool
    {
        try {
            if ($authAccountId = AuthRedis::getAuthAccountId()) {
                AppService::instance()->deactivateEntityRightsRestrictions();
                $account = Account::byId($authAccountId);
                AppService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
                self::setAccount($account);
                return true;
            }
        } catch (Throwable) {
            return false;
        }
        return false;
    }
}
