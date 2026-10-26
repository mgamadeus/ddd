<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect;

use DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect;
use Doctrine\ORM\Configuration;
use PHPUnit\Framework\TestCase;

/** §6 step 2 "pure move": connectionParams() is the identity function, quote() matches today's raw backtick usage. */
class MariaDbDialectTest extends TestCase
{
    protected MariaDbDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new MariaDbDialect();
    }

    public function testConnectionParamsAreUnchanged(): void
    {
        $params = ['driver' => 'pdo_mysql', 'charset' => 'utf8mb4', 'server_version' => '10.6'];
        $this->assertSame($params, $this->dialect->connectionParams($params));
    }

    public function testQuoteWrapsInBackticks(): void
    {
        $this->assertSame('`CounterRows`', $this->dialect->quote('CounterRows'));
    }

    public function testNameAndCapabilities(): void
    {
        $this->assertSame('mariadb', $this->dialect->name());
        $this->assertTrue($this->dialect->capabilities()->isMariaDb());
        $this->assertFalse($this->dialect->capabilities()->isPostgres());
    }

    public function testConfigureOrmRegistersTheCustomFunctionsAndTypesWithoutError(): void
    {
        $configuration = new Configuration();
        $this->dialect->configureOrm($configuration);
        $this->assertNotNull($configuration->getCustomStringFunction('MATCH'));
        $this->assertNotNull($configuration->getCustomNumericFunction('COSINE_DISTANCE'));
    }
}
