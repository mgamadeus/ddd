<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect;

use DDD\Domain\Base\Repo\DB\Dialect\DialectResolver;
use DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect;
use DDD\Domain\Base\Repo\DB\Dialect\PostgresDialect;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** D10: resolution from the driver param, cached per connection, no round trip (works without a reachable server). */
final class DialectResolverTest extends TestCase
{
    public function testForDriverResolvesMariaDb(): void
    {
        $this->assertInstanceOf(MariaDbDialect::class, DialectResolver::forDriver('pdo_mysql'));
    }

    public function testForDriverResolvesPostgres(): void
    {
        $this->assertInstanceOf(PostgresDialect::class, DialectResolver::forDriver('pdo_pgsql'));
    }

    public function testForDriverRejectsAnUnknownDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DialectResolver::forDriver('pdo_sqlite');
    }

    public function testForConnectionResolvesFromTheConnectionsDriverParamWithoutConnecting(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => 'unreachable.invalid']);
        $this->assertInstanceOf(PostgresDialect::class, DialectResolver::forConnection($connection));
    }

    public function testForConnectionCachesTheSameDialectInstancePerConnection(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => 'unreachable.invalid']);
        $this->assertSame(DialectResolver::forConnection($connection), DialectResolver::forConnection($connection));
    }
}
