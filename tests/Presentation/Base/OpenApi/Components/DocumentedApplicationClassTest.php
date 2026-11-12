<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Base\OpenApi\Components;

use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Presentation\Base\OpenApi\Document;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass\ApplicationQueryOptionsProbe;
use DDD\Tests\Domain\Base\Entities\QueryOptions\Fixtures\ApplicationClass\ModuleQueryOptionsProbe;
use DDD\Tests\Presentation\Base\OpenApi\Components\Fixtures\FilePartsProbeRequestDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouteCollection;

/**
 * A module's domain object is documented as the class the application registered in its place — under the
 * application's name and with the properties its class adds — since the objects at runtime are built of it.
 */
class DocumentedApplicationClassTest extends TestCase
{
    protected array $frameworkToAppClassNamesBefore;

    protected Document $document;

    protected function setUp(): void
    {
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        StaticRegistry::$frameworkToAppClassNames[ModuleQueryOptionsProbe::class] = ApplicationQueryOptionsProbe::class;
        $routeCollection = new RouteCollection();
        $this->document = new Document($routeCollection);
    }

    protected function tearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
    }

    public function testTheSchemaReferenceNamesTheApplicationClass(): void
    {
        $moduleClass = new ClassWithNamespace(ModuleQueryOptionsProbe::class);

        self::assertSame(
            '#/components/schemas/' . str_replace('\\', '.', ApplicationQueryOptionsProbe::class),
            $this->document->components->getSchemaRefForClass($moduleClass)
        );
    }

    public function testTheSchemaDocumentsThePropertiesOfTheApplicationClass(): void
    {
        $this->document->components->addSchemaForClass(new ClassWithNamespace(ModuleQueryOptionsProbe::class));

        $schemaKey = str_replace('\\', '.', ApplicationQueryOptionsProbe::class);
        self::assertArrayHasKey($schemaKey, $this->document->components->schemas);
        self::assertArrayNotHasKey(str_replace('\\', '.', ModuleQueryOptionsProbe::class), $this->document->components->schemas);
        self::assertArrayHasKey('agentSpaceKey', $this->document->components->schemas[$schemaKey]->properties);
    }

    public function testADtoIsDocumentedAsItIs(): void
    {
        $dtoClass = new ClassWithNamespace(FilePartsProbeRequestDto::class);

        self::assertSame($dtoClass, $this->document->components->getDocumentedClassWithNamespace($dtoClass));
    }
}
