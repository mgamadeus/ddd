<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Harness;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityManager;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Libs\Config;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Doctrine\DBAL\DriverManager;
use ReflectionProperty;
use RuntimeException;

/**
 * Test-only DBAL middleware for the "T0 golden" baseline (architecture plan
 * `agentic-plans/backend/2026-10-24-ddd-dual-dialect-architecture.md` §5): records every SQL statement
 * unmodified DDD core renders and executes against the harness MariaDB (DDL, upsert/insert/update, pagination,
 * locks), so a later refactored `MariaDbDialect` can be checked against it for byte-identical output.
 *
 * Enabled only when the `DDD_GOLDEN_RECORD` env var names an output directory ({@see self::wireIfEnabled()} is
 * a no-op otherwise) — zero cost in the normal harness run, and no change under `src/`: it reuses the same
 * "rebuild the entity manager on a fresh connection" pattern `EntityManagerFactory::rebuildOnSameConnection()`
 * already applies, just with a Doctrine `Configuration` that carries this middleware.
 *
 * {@see self::wireIfEnabled()} re-checks (cheaply, by driver type) and re-wires on every call rather than once
 * per process: `EntityManagerFactory::getInstance()` silently rebuilds a plain, unwired instance whenever it
 * judges the connection unhealthy or the entity manager closed with no open transaction (see its docblock) —
 * exactly what `TransactionSafeConnectionTest`'s killed-connection fixtures do on purpose. `IntegrationTestCase`
 * calls this at the top of every `setUpBeforeClass()` / `setUp()` / `tearDownAfterClass()`, so a reset mid-suite
 * only loses the statements between the reset and the next of those calls, never the rest of the run.
 *
 * One `.sql` file per PHPUnit test method, plus one for the fixture DDL that `IntegrationTestCase` runs outside
 * any test method (`<ClassName>__setUpBeforeClass` / `__tearDownAfterClass`). Each file holds its statements in
 * execution order: the SQL text, a `-- params: [...]` JSON comment line with the bound values, and a separator.
 * Nondeterministic values are replaced by a placeholder before being written — see {@see self::normalizeValue()}
 * — so two captures of the same run are byte-identical; see `tests/golden/README.md`.
 */
final class RecordingMiddleware implements MiddlewareInterface
{
    private static ?string $currentTestId = null;

    /** @var string[] Buffered "sql\n-- params: ...\n-- ---" entries for the current test */
    private static array $buffer = [];

    public static function isEnabled(): bool
    {
        return self::outputDir() !== null;
    }

    public static function outputDir(): ?string
    {
        $dir = getenv('DDD_GOLDEN_RECORD');
        return ($dir !== false && $dir !== '') ? $dir : null;
    }

    /**
     * Swaps the DEFAULT-scope entity manager for one whose connection was built with this middleware wired in —
     * unless it already is one (cheap check by driver type), in which case this is a no-op. A no-op outright
     * unless recording is enabled. Safe and cheap to call before every setUpBeforeClass()/setUp()/
     * tearDownAfterClass(): see the class docblock for why a one-time wire is not enough.
     */
    public static function wireIfEnabled(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $scope = EntityManagerFactory::SCOPE_DEFAULT;
        // Forces an instance into existence (wired or not) so there is something to inspect/reuse; this is the
        // same call every other harness codepath makes, so it costs nothing extra when already wired.
        $baseEntityManager = EntityManagerFactory::getInstance($scope);
        if ($baseEntityManager->getConnection()->getDriver() instanceof RecordingDriver) {
            return;
        }
        $config = $baseEntityManager->getConfiguration();
        $eventManager = $baseEntityManager->getEventManager();
        $config->setMiddlewares([new self()]);

        // EntityManagerFactory::getDBConfigForScope() is `protected` and builds the identical array from the
        // same env vars — duplicated here (test-only) because there is no seam to inject a wired Connection
        // through the factory itself without touching src/.
        $connectionParams = [
            'driver' => Config::getEnv("DB_{$scope}_CONNECTION_DRIVER"),
            'host' => Config::getEnv("DB_{$scope}_CONNECTION_HOST"),
            'user' => Config::getEnv("DB_{$scope}_CONNECTION_USER"),
            'password' => Config::getEnv("DB_{$scope}_CONNECTION_PASSWORD"),
            'dbname' => Config::getEnv("DB_{$scope}_CONNECTION_DB_NAME"),
            'server_version' => Config::getEnv("DB_{$scope}_CONNECTION_DB_SERVER_VERSION"),
            'charset' => Config::getEnv("DB_{$scope}_CONNECTION_DB_SERVER_CHARSET"),
        ];
        if (Config::getEnv("DB_{$scope}_CONNECTION_HOST_PORT")) {
            $connectionParams['port'] = Config::getEnv("DB_{$scope}_CONNECTION_HOST_PORT");
        }

        $recordingConnection = DriverManager::getConnection($connectionParams, $config, $eventManager);
        $recordingEntityManager = new DoctrineEntityManager($recordingConnection, $config, $eventManager);

        $instancesProperty = new ReflectionProperty(EntityManagerFactory::class, 'instances');
        $instancesProperty->setAccessible(true);
        $instances = $instancesProperty->getValue();
        $instances[$scope] = $recordingEntityManager;
        $instancesProperty->setValue(null, $instances);
    }

    public static function beginTest(string $testId): void
    {
        self::$currentTestId = self::sanitizeTestId($testId);
        self::$buffer = [];
    }

    public static function endTest(): void
    {
        if (self::$currentTestId === null) {
            return;
        }
        $outputDir = self::outputDir();
        if ($outputDir !== null) {
            if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
                throw new RuntimeException("Could not create golden output directory \"$outputDir\".");
            }
            file_put_contents($outputDir . '/' . self::$currentTestId . '.sql', implode("\n", self::$buffer) . "\n");
        }
        self::$currentTestId = null;
        self::$buffer = [];
    }

    /** @param array<int|string, mixed> $params Bound values keyed by their 1-indexed position or name */
    public static function record(string $sql, array $params): void
    {
        if (self::$currentTestId === null || !self::isEnabled()) {
            return;
        }
        ksort($params);
        $normalizedParams = array_map([self::class, 'normalizeValue'], $params);
        self::$buffer[] = rtrim($sql) . "\n-- params: "
            . json_encode($normalizedParams, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n-- ----------------------------------------";
    }

    /**
     * Placeholders for values that are expected to differ between two otherwise-identical captures: a
     * wall-clock timestamp DDD stamps on write (`<<DATETIME>>`), or a UUID-shaped random token
     * (`<<UUID>>`). Anything else is recorded as-is — normalizing more than this would risk hiding a genuine
     * dialect regression behind a placeholder. Document any addition here AND in tests/golden/README.md.
     */
    public static function normalizeValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?$/', $value) === 1) {
            return '<<DATETIME>>';
        }
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value) === 1) {
            return '<<UUID>>';
        }
        return $value;
    }

    private static function sanitizeTestId(string $testId): string
    {
        $sanitized = str_replace('::', '__', $testId);
        $sanitized = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $sanitized);
        return trim($sanitized, '_');
    }

    public function wrap(Driver $driver): Driver
    {
        return new RecordingDriver($driver);
    }
}
