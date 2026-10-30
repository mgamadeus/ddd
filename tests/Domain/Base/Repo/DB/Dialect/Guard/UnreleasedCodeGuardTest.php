<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect\Guard;

use DDD\Domain\Base\Repo\DB\Dialect\Guard\InstalledPackageInfo;
use DDD\Domain\Base\Repo\DB\Dialect\Guard\UnreleasedCodeGuard;
use DDD\Domain\Base\Repo\DB\Dialect\Guard\UnreleasedCodeProductionGuardException;
use PHPUnit\Framework\TestCase;

class FakeInstalledPackageInfo implements InstalledPackageInfo
{
    public function __construct(protected readonly string $prettyVersion, protected readonly bool $isPathInstall = false)
    {
    }

    public function prettyVersion(): string
    {
        return $this->prettyVersion;
    }

    public function isPathInstall(): bool
    {
        return $this->isPathInstall;
    }
}

/**
 * §6 build step 2 guard fixtures: dev + listed host → boot error; path install with a tagged version + listed
 * IP:port → error (path install always wins over the version string); empty allowlist → error (fails closed);
 * tagged → boots; `-RC1` + listed host → boots. The allowlist comes from DB_PRODUCTION_HOSTS / DB_PRODUCTION_PORTS
 * in the environment ($_ENV, as Symfony's Dotenv fills it), never from the package.
 */
class UnreleasedCodeGuardTest extends TestCase
{
    protected const array LISTED_HOST_PARAMS = ['host' => 'db.production.example', 'port' => 5432];
    protected const array LISTED_PORT_PARAMS = ['host' => 'some-dev-host.internal', 'port' => 16033];
    protected const array SAFE_PARAMS = ['host' => '127.0.0.1', 'port' => 3317];

    protected array $environmentBefore = [];

    protected function setUp(): void
    {
        foreach (['DB_PRODUCTION_HOSTS', 'DB_PRODUCTION_PORTS'] as $environmentName) {
            $this->environmentBefore[$environmentName] = $_ENV[$environmentName] ?? null;
        }
        $_ENV['DB_PRODUCTION_HOSTS'] = 'db.production.example, replica.production.example';
        $_ENV['DB_PRODUCTION_PORTS'] = '16033,13306';
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
    }

    public function testThePackageShipsNoProductionHostWithoutTheEnvironment(): void
    {
        unset($_ENV['DB_PRODUCTION_HOSTS'], $_ENV['DB_PRODUCTION_PORTS']);

        $this->expectException(UnreleasedCodeProductionGuardException::class);
        $this->expectExceptionMessageMatches('/allowlist is empty/');
        UnreleasedCodeGuard::assertAllowed(self::SAFE_PARAMS, new FakeInstalledPackageInfo('dev-postgres'));
    }

    public function testASecondListedHostFromTheEnvironmentIsRefused(): void
    {
        $this->expectException(UnreleasedCodeProductionGuardException::class);
        UnreleasedCodeGuard::assertAllowed(['host' => 'replica.production.example', 'port' => 5432], new FakeInstalledPackageInfo('dev-main'));
    }

    public function testADevInstallAgainstAListedHostBootError(): void
    {
        $this->expectException(UnreleasedCodeProductionGuardException::class);
        UnreleasedCodeGuard::assertAllowed(self::LISTED_HOST_PARAMS, new FakeInstalledPackageInfo('dev-postgres'));
    }

    public function testADevInstallAgainstAListedProductionPortBootError(): void
    {
        $this->expectException(UnreleasedCodeProductionGuardException::class);
        UnreleasedCodeGuard::assertAllowed(self::LISTED_PORT_PARAMS, new FakeInstalledPackageInfo('dev-main'));
    }

    public function testAPathInstallWithATaggedVersionAndAListedHostPortStillErrors(): void
    {
        $this->expectException(UnreleasedCodeProductionGuardException::class);
        UnreleasedCodeGuard::assertAllowed(self::LISTED_HOST_PARAMS, new FakeInstalledPackageInfo('2.70.9', isPathInstall: true));
    }

    public function testAnEmptyAllowlistFailsClosedEvenAgainstASafeHost(): void
    {
        $this->expectException(UnreleasedCodeProductionGuardException::class);
        UnreleasedCodeGuard::assertAllowed(
            self::SAFE_PARAMS,
            new FakeInstalledPackageInfo('dev-postgres'),
            productionHosts: [],
            productionPorts: []
        );
    }

    public function testATaggedVersionBoots(): void
    {
        UnreleasedCodeGuard::assertAllowed(self::LISTED_HOST_PARAMS, new FakeInstalledPackageInfo('2.70.9'));
        $this->addToAssertionCount(1);
    }

    public function testAReleaseCandidateTagBoots(): void
    {
        UnreleasedCodeGuard::assertAllowed(self::LISTED_HOST_PARAMS, new FakeInstalledPackageInfo('2.80.0-RC1'));
        $this->addToAssertionCount(1);
    }

    public function testADevInstallAgainstASafeHostBoots(): void
    {
        UnreleasedCodeGuard::assertAllowed(self::SAFE_PARAMS, new FakeInstalledPackageInfo('dev-postgres'));
        $this->addToAssertionCount(1);
    }

    public function testAVPrefixedTagBoots(): void
    {
        UnreleasedCodeGuard::assertAllowed(self::LISTED_HOST_PARAMS, new FakeInstalledPackageInfo('v2.70.9'));
        $this->addToAssertionCount(1);
    }
}
