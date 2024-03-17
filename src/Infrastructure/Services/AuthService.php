<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Services;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Exceptions\UnauthorizedException;
use DDD\Infrastructure\Libs\AuthRedis;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Libs\JWTPayload;
use Doctrine\ORM\NonUniqueResultException;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Throwable;

class AuthService
{
    protected static ?Account $account = null;
    protected static ?Account $adminAccount = null;

    protected ?AccountsService $accountsService;


    /** @var AuthService */
    protected static $instance;

    public function __construct(?AccountsService $accountsService = null)
    {
        if (!$accountsService) {
            /** @var AccountsService $accountsService */
            $accountsService = DDDService::instance()->getService(
                AccountsService::class
            );
        }
        $this->accountsService = $accountsService;
    }

    public static function instance(): self
    {
        if (isset(self::$instance)) {
            return self::$instance;
        }
        /** @var self $instance */
        $instance = DDDService::instance()->getService(self::class);
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
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $account = Account::getService()->find($accountId);
        $this->setAccount($account);
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
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
            $decoded = JWT::decode(
                $jwt,
                new Key(Config::getEnv('AUTH_JWT_HASH_KEY'), Config::getEnv('AUTH_JWT_HASH_METHOD'))
            );

            if ($decoded->user_id) {
                DDDService::instance()->deactivateEntityRightsRestrictions();
                $account = Account::byId($decoded->user_id);
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
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
                DDDService::instance()->deactivateEntityRightsRestrictions();
                $account = Account::byId($authAccountId);
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
                self::setAccount($account);
                return true;
            }
        } catch (Throwable) {
            return false;
        }
        return false;
    }

    /**
     * Creates a new refresh token with renewed expiration date
     * @param string $refreshToken
     * @return string
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws UnauthorizedException
     */
    public function extendRefreshToken(string $refreshToken): string
    {
        $decoded = JWTPayload::getParametersFromJWT($refreshToken);
        if (!$decoded || !($decoded['accountId'] ?? null)) {
            throw new UnauthorizedException('Refresh token invalid');
        }
        return $this->getRefreshTokenForAccountId($decoded['accountId']);
    }

    /**
     * Generates Refresh Token for Account, refresh tokens cannot be used for Login, only for obtaining a new Login Token
     * @param string|int $accountId
     * @param bool $isShortLived
     * @return string
     */
    public function getRefreshTokenForAccountId(string|int $accountId, bool $isShortLived = false): string
    {
        $ttl = $isShortLived ? Config::getEnv('AUTH_REFRESH_TOKEN_SHORT_TTL') : Config::getEnv(
            'AUTH_REFRESH_TOKEN_TTL'
        );

        return JWTPayload::createJWTFromParameters(['accountId' => $accountId, 'refreshToken' => true], $ttl);
    }

    /**
     * Generates access token for Account based on refresh token
     * @param string $refreshToken
     * @param bool $isShortLived
     * @return string
     * @throws UnauthorizedException
     */
    public function getAccessTokenForAccountBasedOnRefreshToken(
        string $refreshToken,
        bool $isShortLived = false
    ): string {
        $decoded = JWTPayload::getParametersFromJWT($refreshToken);
        if (!$decoded || !($decoded['accountId'] ?? null)) {
            throw new UnauthorizedException('Refresh token invalid');
        }
        $ttl = $isShortLived ? Config::getEnv('AUTH_LOGIN_TOKEN_SHORT_TTL') : Config::getEnv(
            'AUTH_LOGIN_TOKEN_TTL'
        );

        return JWTPayload::createJWTFromParameters(['accountId' => $decoded['accountId']], $ttl);
    }

    /**
     * @param string $accountId
     * @param string $password
     * @param bool $isShortLived
     * @return string
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws UnauthorizedException
     * @throws NotFoundException
     */
    public function forceAuthenticateAccount(string $accountId, string $password, bool $isShortLived = false): string
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->accountsService->throwErrors = true;
        $account = $this->accountsService->find($accountId);
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        if (!$account || $account->password !== $password) {
            throw new UnauthorizedException('Wrong credentials.');
        }
        return $this->getRefreshTokenForAccountId($account->id, $isShortLived);
    }

    /**
     * @param string $email
     * @param string $password
     * @return string
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws NonUniqueResultException
     * @throws ReflectionException
     * @throws UnauthorizedException
     */
    public function getRefreshTokenForAccount(string $email, string $password): string
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->accountsService->throwErrors = true;
        $account = $this->accountsService->getAccountByEmail($email);
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        $password = hash_hmac(
            Config::getEnv('AUTH_PASSWORD_HASH_METHOD'),
            $password,
            Config::getEnv('AUTH_PASSWORD_HASH_KEY')
        );
        if ($account->password !== $password) {
            throw new UnauthorizedException('Wrong credentials.');
        }
        return $this->getRefreshTokenForAccountId($account->id);
    }
}
