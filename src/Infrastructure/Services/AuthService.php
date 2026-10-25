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
    /**
     * @var string The claim that marks a JWT as a LOGIN credential. Applications sign many JWTs with the same key
     *      (image/media access URLs, deep links, OAuth state); without a positive type every one of them that carries
     *      an `accountId` would log in. Only {@see self::TOKEN_TYPE_ACCESS} is accepted by the bearer authenticators.
     */
    public const string CLAIM_TOKEN_TYPE = 'tokenType';

    /** @var string The only token type the bearer authenticators log in — minted by the refresh exchange, nowhere else */
    public const string TOKEN_TYPE_ACCESS = 'access';

    /** @var string The marker {@see self::getRefreshTokenForAccountId()} writes; only such a token may be exchanged */
    public const string CLAIM_REFRESH_TOKEN = 'refreshToken';

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
     * @param int $accountId
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
                new Key(JWTPayload::getHashKey(), JWTPayload::getHashMethod())
            );

            if ($decoded->user_id) {
                DDDService::instance()->deactivateEntityRightsRestrictions();
                $account = Account::byId($decoded->user_id);
                DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
                self::setAccount($account);
            }
        } catch (Exception) {
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
        return $this->getRefreshTokenForAccountId($this->getAccountIdFromRefreshTokenOrThrow($refreshToken));
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

        return JWTPayload::createJWTFromParameters(['accountId' => $accountId, self::CLAIM_REFRESH_TOKEN => true], $ttl);
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
        $accountId = $this->getAccountIdFromRefreshTokenOrThrow($refreshToken);
        $ttl = $isShortLived ? Config::getEnv('AUTH_LOGIN_TOKEN_SHORT_TTL') : Config::getEnv(
            'AUTH_LOGIN_TOKEN_TTL'
        );

        return JWTPayload::createJWTFromParameters(
            ['accountId' => $accountId, self::CLAIM_TOKEN_TYPE => self::TOKEN_TYPE_ACCESS],
            $ttl
        );
    }

    /**
     * The account a REFRESH token was minted for. Refused (401) unless the token is a refresh token in the sense of
     * {@see self::isRefreshTokenPayload()}: an access token, a purpose token (image URL, deep link) or an
     * impersonation token cannot be exchanged for a fresh 30-day credential. The one decision point for every
     * refresh exchange — an application overriding the exchange calls it (or {@see self::isRefreshTokenPayload()}
     * on a payload it decoded itself, e.g. in an expired-token branch).
     *
     * @param string $refreshToken
     * @return string|int
     * @throws UnauthorizedException
     */
    protected function getAccountIdFromRefreshTokenOrThrow(string $refreshToken): string|int
    {
        $payload = JWTPayload::getParametersFromJWT($refreshToken);
        if (!static::isRefreshTokenPayload($payload)) {
            throw new UnauthorizedException('Refresh token invalid');
        }
        return $payload['accountId'];
    }

    /**
     * True for the decoded payload of a token that may LOG IN: it names an account and carries
     * `tokenType: access`. Every bearer authenticator — the framework's and an application's own (a cookie bearer
     * authenticator, for instance) — decides with this, so the rule lives in one place.
     *
     * @param array|bool $payload The result of {@see JWTPayload::getParametersFromJWT()}; false for an invalid token
     * @return bool
     */
    public static function isAccessTokenPayload(array|bool $payload): bool
    {
        return is_array($payload)
            && !empty($payload['accountId'])
            && ($payload[self::CLAIM_TOKEN_TYPE] ?? null) === self::TOKEN_TYPE_ACCESS;
    }

    /**
     * True for the decoded payload of a token that may be EXCHANGED: it names an account, carries the refresh marker
     * and has no token type. A token with any `tokenType` — an access token, or an impersonation token minted as one —
     * is never a refresh token, whatever else it contains.
     *
     * @param array|bool $payload The result of {@see JWTPayload::getParametersFromJWT()}; false for an invalid token
     * @return bool
     */
    public static function isRefreshTokenPayload(array|bool $payload): bool
    {
        return is_array($payload)
            && !empty($payload['accountId'])
            && ($payload[self::CLAIM_REFRESH_TOKEN] ?? null) === true
            && !isset($payload[self::CLAIM_TOKEN_TYPE]);
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
        $account = $this->accountsService->findByEmail($email);
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
