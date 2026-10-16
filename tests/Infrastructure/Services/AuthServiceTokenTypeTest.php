<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Services;

use DDD\Infrastructure\Exceptions\UnauthorizedException;
use DDD\Infrastructure\Libs\JWTPayload;
use DDD\Infrastructure\Services\AuthService;
use DDD\Symfony\Security\Authenticators\TokenAuthenticator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;

/**
 * Every JWT the application mints is signed with the same key. Only a token typed `access` may log in, and only a
 * refresh token (marker set, no type) may be exchanged for new credentials — whatever else a token contains.
 */
class AuthServiceTokenTypeTest extends TestCase
{
    protected const string TEST_KEY = 'phpunit-shared-signing-key-of-sufficient-length-0123456789';

    protected function setUp(): void
    {
        foreach (['JWT_HASH_KEY', 'AUTH_JWT_HASH_KEY'] as $keyName) {
            $_ENV[$keyName] = self::TEST_KEY;
        }
        foreach (['JWT_HASH_METHOD', 'AUTH_JWT_HASH_METHOD'] as $methodName) {
            $_ENV[$methodName] = 'HS256';
        }
        foreach (['AUTH_LOGIN_TOKEN_TTL', 'AUTH_LOGIN_TOKEN_SHORT_TTL', 'AUTH_REFRESH_TOKEN_TTL', 'AUTH_REFRESH_TOKEN_SHORT_TTL'] as $ttlName) {
            $_ENV[$ttlName] = '3600';
        }
    }

    protected function authService(): AuthService
    {
        /** @var AuthService $authService */
        $authService = (new ReflectionClass(AuthService::class))->newInstanceWithoutConstructor();
        return $authService;
    }

    protected function authenticatorPassportAccountIdFor(string $jwt): string
    {
        /** @var TokenAuthenticator $tokenAuthenticator */
        $tokenAuthenticator = (new ReflectionClass(TokenAuthenticator::class))->newInstanceWithoutConstructor();
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ' . $jwt);
        return $tokenAuthenticator->authenticate($request)->getBadge(
            \Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class
        )->getUserIdentifier();
    }

    /** @return array<string, array{0: array}> Tokens an application signs with the same key that must NOT log in */
    public static function nonAccessTokens(): array
    {
        return [
            'refresh token' => [['accountId' => 42, AuthService::CLAIM_REFRESH_TOKEN => true]],
            'image access token' => [['accountId' => 42, 'mediaId' => 7]],
            'bare accountId (agency logo)' => [['accountId' => 42]],
            'deep link' => [['accountId' => 42, 'locationId' => 9]],
            'other token type' => [['accountId' => 42, AuthService::CLAIM_TOKEN_TYPE => 'media']],
        ];
    }

    public function testTheRefreshExchangeMintsATypedAccessTokenThatLogsIn(): void
    {
        $authService = $this->authService();
        $refreshToken = $authService->getRefreshTokenForAccountId(42);

        $accessToken = $authService->getAccessTokenForAccountBasedOnRefreshToken($refreshToken);

        $payload = JWTPayload::getParametersFromJWT($accessToken);
        $this->assertSame(AuthService::TOKEN_TYPE_ACCESS, $payload[AuthService::CLAIM_TOKEN_TYPE]);
        $this->assertSame('42', $this->authenticatorPassportAccountIdFor($accessToken));
    }

    /** @dataProvider nonAccessTokens */
    public function testTheAuthenticatorRefusesEveryTokenThatIsNotAnAccessToken(array $claims): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->authenticatorPassportAccountIdFor(JWTPayload::createJWTFromParameters($claims, 3600));
    }

    /** @return array<string, array{0: array}> Tokens that must NOT be exchanged for new credentials */
    public static function nonRefreshTokens(): array
    {
        return [
            'access token' => [['accountId' => 42, AuthService::CLAIM_TOKEN_TYPE => AuthService::TOKEN_TYPE_ACCESS]],
            'image access token' => [['accountId' => 42, 'mediaId' => 7]],
            'bare accountId' => [['accountId' => 42]],
            'impersonation token minted as access token' => [[
                'accountId' => 42,
                'impersonatingAdminAccountId' => 1,
                AuthService::CLAIM_TOKEN_TYPE => AuthService::TOKEN_TYPE_ACCESS,
            ]],
            'refresh marker on a typed token' => [[
                'accountId' => 42,
                AuthService::CLAIM_REFRESH_TOKEN => true,
                AuthService::CLAIM_TOKEN_TYPE => AuthService::TOKEN_TYPE_ACCESS,
            ]],
            'refresh marker not literally true' => [['accountId' => 42, AuthService::CLAIM_REFRESH_TOKEN => 1]],
        ];
    }

    /** @dataProvider nonRefreshTokens */
    public function testTheAccessExchangeRefusesEveryTokenThatIsNotARefreshToken(array $claims): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->authService()->getAccessTokenForAccountBasedOnRefreshToken(JWTPayload::createJWTFromParameters($claims, 3600));
    }

    /** @dataProvider nonRefreshTokens */
    public function testTheRefreshExtensionRefusesEveryTokenThatIsNotARefreshToken(array $claims): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->authService()->extendRefreshToken(JWTPayload::createJWTFromParameters($claims, 3600));
    }

    public function testARealRefreshTokenIsExtended(): void
    {
        $authService = $this->authService();

        $extendedRefreshToken = $authService->extendRefreshToken($authService->getRefreshTokenForAccountId(42));

        $this->assertTrue(AuthService::isRefreshTokenPayload(JWTPayload::getParametersFromJWT($extendedRefreshToken)));
    }

    public function testAnInvalidTokenIsNeitherAnAccessNorARefreshToken(): void
    {
        $this->assertFalse(AuthService::isAccessTokenPayload(false));
        $this->assertFalse(AuthService::isRefreshTokenPayload(false));
        $this->assertFalse(AuthService::isAccessTokenPayload([AuthService::CLAIM_TOKEN_TYPE => AuthService::TOKEN_TYPE_ACCESS]));
    }
}
