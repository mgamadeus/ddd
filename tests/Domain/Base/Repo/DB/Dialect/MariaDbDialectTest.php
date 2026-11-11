<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Repo\DB\Dialect;

use DDD\Domain\Base\Repo\DB\Dialect\Json\JsonPath;
use DDD\Domain\Base\Repo\DB\Dialect\MariaDbDialect;
use Doctrine\DBAL\DriverManager;
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

    public function testTransactionFragmentsRenderTheMariaDbForms(): void
    {
        $this->assertSame(
            "GROUP_CONCAT(DISTINCT `label` ORDER BY `label` SEPARATOR ', ')",
            $this->dialect->stringAggDistinct('`label`', ', ')
        );
        $this->assertSame('LOCK IN SHARE MODE', $this->dialect->forShare());
        $this->assertSame(
            'INSERT INTO `Rows` (`code`) VALUES (?) ON DUPLICATE KEY UPDATE `Rows`.`code` = `Rows`.`code`',
            $this->dialect->insertSkippingExistingRows("INSERT INTO `Rows` (`code`) VALUES (?);\n", 'Rows', 'code')
        );
    }

    public function testNumberReadersParseTheDocumentOnce(): void
    {
        $this->assertSame(
            "CAST(JSON_VALUE(`document`, '$.\"cost\"') AS DECIMAL(16, 8))",
            $this->dialect->jsonDecimal('`document`', JsonPath::fromKeys('cost'), 16, 8)
        );
        $this->assertSame("CAST(JSON_VALUE(`document`, '$.\"number\"') AS SIGNED)", $this->dialect->jsonInt('`document`', JsonPath::fromKeys('number')));
        $this->assertSame('OCTET_LENGTH(`label`)', $this->dialect->byteLength('`label`'));
    }

    public function testLocksOfOneConnectionShareOneInstance(): void
    {
        $firstConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $secondConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->assertSame($this->dialect->locks($firstConnection), $this->dialect->locks($firstConnection));
        $this->assertNotSame($this->dialect->locks($firstConnection), $this->dialect->locks($secondConnection));
    }
}
