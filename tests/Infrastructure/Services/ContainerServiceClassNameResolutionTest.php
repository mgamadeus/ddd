<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Services;

use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Infrastructure\Services\Fixtures\ClassNameResolvingDDDService;
use ErrorException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixtures/MirroredAppClass.php';

/**
 * A framework class name whose application mirror exists at the same path resolves to the application class — also
 * when the compiled service class map does not list it — and the resolution is cached as the application class.
 */
class ContainerServiceClassNameResolutionTest extends TestCase
{
    protected const string FRAMEWORK_CLASS_NAME = 'DDD\Tests\Infrastructure\Services\Fixtures\MirroredAppClass';
    protected const string APP_CLASS_NAME = 'App\Tests\Infrastructure\Services\Fixtures\MirroredAppClass';

    protected mixed $containerServiceClassMapBefore;
    protected array $frameworkToAppClassNamesBefore;

    protected function setUp(): void
    {
        $this->containerServiceClassMapBefore = StaticRegistry::$containerServiceClassMap;
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        StaticRegistry::$containerServiceClassMap = ['Some\Other\Service' => 'Some\Other\Service'];
        unset(StaticRegistry::$frameworkToAppClassNames[self::FRAMEWORK_CLASS_NAME]);
    }

    protected function tearDown(): void
    {
        StaticRegistry::$containerServiceClassMap = $this->containerServiceClassMapBefore;
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
    }

    public function testTheMirroredApplicationClassIsResolvedAndCached(): void
    {
        self::assertSame(
            self::APP_CLASS_NAME,
            ClassNameResolvingDDDService::resolveContainerServiceClassName(self::FRAMEWORK_CLASS_NAME)
        );
        self::assertSame(self::APP_CLASS_NAME, StaticRegistry::$frameworkToAppClassNames[self::FRAMEWORK_CLASS_NAME]);
    }

    public function testAThrowingErrorHandlerDoesNotTurnTheHitIntoTheFrameworkClass(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $resolvedClassName = ClassNameResolvingDDDService::resolveContainerServiceClassName(self::FRAMEWORK_CLASS_NAME);
        } finally {
            restore_error_handler();
        }

        self::assertSame(self::APP_CLASS_NAME, $resolvedClassName);
        self::assertSame(
            self::APP_CLASS_NAME,
            ClassNameResolvingDDDService::resolveContainerServiceClassName(self::FRAMEWORK_CLASS_NAME)
        );
    }
}
