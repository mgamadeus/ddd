<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Libs;

use DDD\Infrastructure\Libs\JWTPayload;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * One key pair signs and verifies every JWT: JWT_HASH_KEY / JWT_HASH_METHOD. Verification used to read the second
 * pair AUTH_JWT_HASH_KEY / AUTH_JWT_HASH_METHOD, so a deployment that generated the two keys independently could not
 * log in. The old pair stays a deprecated fallback while JWT_HASH_* is empty.
 */
class JWTPayloadKeyTest extends TestCase
{
    protected const array ENV_NAMES = ['JWT_HASH_KEY', 'JWT_HASH_METHOD', 'AUTH_JWT_HASH_KEY', 'AUTH_JWT_HASH_METHOD'];

    protected array $environmentBefore = [];

    protected function setUp(): void
    {
        foreach (self::ENV_NAMES as $environmentName) {
            $this->environmentBefore[$environmentName] = $_ENV[$environmentName] ?? null;
            unset($_ENV[$environmentName]);
        }
        (new ReflectionProperty(JWTPayload::class, 'legacyFallbackReported'))->setValue(null, false);
    }

    protected function tearDown(): void
    {
        foreach ($this->environmentBefore as $environmentName => $value) {
            if ($value === null) {
                unset($_ENV[$environmentName]);
            } else {
                $_ENV[$environmentName] = $value;
            }
        }
        (new ReflectionProperty(JWTPayload::class, 'legacyFallbackReported'))->setValue(null, false);
    }

    /** @return string[] The deprecation messages raised while $callable ran */
    protected function deprecationsRaisedBy(callable $callable): array
    {
        $deprecations = [];
        set_error_handler(static function (int $type, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;
            return true;
        }, E_USER_DEPRECATED);
        try {
            $callable();
        } finally {
            restore_error_handler();
        }
        return $deprecations;
    }

    public function testATokenRoundTripsWithOnlyTheJwtHashPairSet(): void
    {
        $_ENV['JWT_HASH_KEY'] = 'the-one-key';
        $_ENV['JWT_HASH_METHOD'] = 'HS256';

        $deprecations = $this->deprecationsRaisedBy(function (): void {
            $jwt = JWTPayload::createJWTFromParameters(['accountId' => 42], 60);
            $this->assertSame(42, JWTPayload::getParametersFromJWT($jwt)['accountId']);
        });

        $this->assertSame([], $deprecations);
    }

    public function testATokenSignedWithAnotherKeyIsRejected(): void
    {
        $_ENV['JWT_HASH_KEY'] = 'the-one-key';
        $_ENV['JWT_HASH_METHOD'] = 'HS256';
        $foreignJwt = JWT::encode(['accountId' => 42, 'expiresAt' => time() + 60], 'another-key', 'HS256');

        $this->assertFalse(JWTPayload::getParametersFromJWT($foreignJwt));
    }

    public function testTheJwtHashPairWinsOverTheLegacyPair(): void
    {
        $_ENV['JWT_HASH_KEY'] = 'the-one-key';
        $_ENV['JWT_HASH_METHOD'] = 'HS256';
        $_ENV['AUTH_JWT_HASH_KEY'] = 'a-different-legacy-key';
        $_ENV['AUTH_JWT_HASH_METHOD'] = 'HS512';

        $this->assertSame('the-one-key', JWTPayload::getHashKey());
        $this->assertSame('HS256', JWTPayload::getHashMethod());
    }

    public function testWithOnlyTheLegacyPairSetItIsUsedAndReportedOnce(): void
    {
        $_ENV['AUTH_JWT_HASH_KEY'] = 'the-legacy-key';
        $_ENV['AUTH_JWT_HASH_METHOD'] = 'HS256';

        $deprecations = $this->deprecationsRaisedBy(function (): void {
            $jwt = JWTPayload::createJWTFromParameters(['accountId' => 7], 60);
            $this->assertSame(7, JWTPayload::getParametersFromJWT($jwt)['accountId']);
            JWTPayload::getHashKey();
        });

        $this->assertCount(1, $deprecations, 'reported once per process');
        $this->assertStringContainsString('AUTH_JWT_HASH_KEY', $deprecations[0]);
        $this->assertSame('the-legacy-key', JWTPayload::getHashKey());
    }

    public function testAnAllDigitKeyIsUsedAsAString(): void
    {
        // Config::getEnv() turns an all-digit value into an int; the key must still reach firebase/jwt as a string
        $_ENV['JWT_HASH_KEY'] = '1234567890';
        $_ENV['JWT_HASH_METHOD'] = 'HS256';

        $jwt = JWTPayload::createJWTFromParameters(['accountId' => 1], 60);

        $this->assertSame(1, JWTPayload::getParametersFromJWT($jwt)['accountId']);
    }
}
