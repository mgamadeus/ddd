<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect;

use DDD\Domain\Base\Repo\DB\Dialect\DatabaseConfigurationException;
use DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect;
use PDO;
use PHPUnit\Framework\TestCase;

/** D4.4: charset never reaches DBAL's pgsql driver, utf8mb4 is refused outright, server_version maps to serverVersion. */
final class PostgresDialectTest extends TestCase
{
    private PostgresDialect $dialect;

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
}
