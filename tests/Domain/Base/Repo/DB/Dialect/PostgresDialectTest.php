<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect;

use DDD\Domain\Base\Repo\DB\Dialect\DatabaseConfigurationException;
use DDD\Domain\Base\Repo\DB\Dialect\Locks\PostgresLockService;
use DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect;
use DDD\Domain\Base\Repo\DB\Dialect\Schema\PostgresDdlRenderer;
use Doctrine\DBAL\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

/** D4.4: charset never reaches DBAL's pgsql driver, utf8mb4 is refused outright, server_version maps to serverVersion. */
class PostgresDialectTest extends TestCase
{
    protected PostgresDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new PostgresDialect();
    }

    public function testCharsetIsUnsetEvenWhenEmpty(): void
    {
        $params = $this->dialect->connectionParams(['charset' => '', 'host' => 'db']);
        $this->assertArrayNotHasKey('charset', $params);
    }

    public function testUtf8mb4CharsetIsRefused(): void
    {
        $this->expectException(DatabaseConfigurationException::class);
        $this->dialect->connectionParams(['charset' => 'utf8mb4']);
    }

    public function testServerVersionMapsToServerVersionCamelCase(): void
    {
        $params = $this->dialect->connectionParams(['server_version' => '16.4']);
        $this->assertSame('16.4', $params['serverVersion']);
        $this->assertArrayNotHasKey('server_version', $params);
    }

    public function testDisablePreparesIsPinned(): void
    {
        $params = $this->dialect->connectionParams([]);
        $this->assertTrue($params['driverOptions'][PDO::PGSQL_ATTR_DISABLE_PREPARES]);
    }

    public function testQuoteWrapsInDoubleQuotesAndEscapesEmbeddedOnes(): void
    {
        $this->assertSame('"CounterRows"', $this->dialect->quote('CounterRows'));
        $this->assertSame('"a""b"', $this->dialect->quote('a"b'));
    }

    public function testNameAndCapabilities(): void
    {
        $this->assertSame('postgres', $this->dialect->name());
        $this->assertTrue($this->dialect->capabilities()->isPostgres());
        $this->assertFalse($this->dialect->capabilities()->isMariaDb());
    }

    /** §6 step 4a: ddlRenderer() hands out a fresh PostgresDdlRenderer (and so a fresh IdentifierPolicy) per call. */
    public function testDdlRendererReturnsAFreshInstanceEveryCall(): void
    {
        $first = $this->dialect->ddlRenderer();
        $second = $this->dialect->ddlRenderer();
        $this->assertInstanceOf(PostgresDdlRenderer::class, $first);
        $this->assertInstanceOf(PostgresDdlRenderer::class, $second);
        $this->assertNotSame($first, $second);
    }

    /**
     * §4.12 fix: `locks()` must hand back the SAME {@see PostgresLockService} for repeated calls with the same
     * scope on the same `PostgresDialect` instance (which is itself the process-wide singleton
     * {@see \DDD\Domain\Base\Repo\DB\Dialect\DialectResolver} caches) — otherwise a nested
     * `AdvisoryLock::synchronized()` acquiring a name this request already holds goes through a FRESH instance
     * with an empty depth map, which opens a SECOND direct connection and blocks on its own held lock (the exact
     * "Could not acquire advisory lock … within 10s" self-timeout this test guards against). $connection is
     * irrelevant to the cache key — PostgresLockService never uses it (see {@see SqlDialect::locks()}'s
     * docblock) — only $scope is.
     */
    public function testLocksReturnsTheSameInstancePerScope(): void
    {
        $connection = $this->createMock(Connection::class);

        $firstDefault = $this->dialect->locks($connection);
        $secondDefault = $this->dialect->locks($connection, 'DEFAULT');
        $this->assertInstanceOf(PostgresLockService::class, $firstDefault);
        $this->assertSame($firstDefault, $secondDefault, 'two calls for the same scope must share one instance');

        $otherScope = $this->dialect->locks($connection, 'OTHER');
        $this->assertNotSame($firstDefault, $otherScope, 'a different scope must get its own instance');

        // A second, independent PostgresDialect (simulating a different process — PHP has no cross-process
        // statics) gets its own cache, so it is NOT the same instance either.
        $freshProcessDialect = new PostgresDialect();
        $this->assertNotSame($firstDefault, $freshProcessDialect->locks($connection));
    }
}
