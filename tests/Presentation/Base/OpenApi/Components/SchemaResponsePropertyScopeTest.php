<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Base\OpenApi\Components;

use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Ignore;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\OpenApi\Components\Schema;
use PHPUnit\Framework\TestCase;

class SchemaScopeProbeDto extends RestResponseDto
{
    public ?string $plainProperty = null;

    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $responseOnlyProperty = null;

    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $queryProperty = null;

    #[Ignore]
    public ?string $ignoredProperty = null;
}

class SchemaResponsePropertyScopeTest extends TestCase
{
    /** @return string[] the property names the generated schema documents */
    protected function documentedPropertyNames(string $scope): array
    {
        $classWithNamespace = new ClassWithNamespace(SchemaScopeProbeDto::class);
        $schema = new Schema($classWithNamespace, $scope);
        $schema->buildSchema();
        return is_object($schema->properties) ? [] : array_keys($schema->properties);
    }

    public function testAResponseScopedSchemaDocumentsEverything(): void
    {
        $documented = $this->documentedPropertyNames(Parameter::RESPONSE);
        $this->assertContains('plainProperty', $documented);
        $this->assertContains('responseOnlyProperty', $documented);
        $this->assertContains('queryProperty', $documented);
    }

    public function testAResponsePropertySurvivesABodyScopedSchema(): void
    {
        // Components are keyed by class name alone and a nested class is registered with the default BODY scope,
        // so a response field must not depend on which path registered the class first.
        $documented = $this->documentedPropertyNames(Parameter::BODY);
        $this->assertContains('responseOnlyProperty', $documented);
        $this->assertContains('plainProperty', $documented);
    }

    public function testAResponsePropertySurvivesAQueryScopedSchema(): void
    {
        $this->assertContains('responseOnlyProperty', $this->documentedPropertyNames(Parameter::QUERY));
    }

    public function testRequestScopeFilteringStillApplies(): void
    {
        // the reason the condition exists: a query parameter has no business in a body schema
        $this->assertNotContains('queryProperty', $this->documentedPropertyNames(Parameter::BODY));
        $this->assertContains('queryProperty', $this->documentedPropertyNames(Parameter::QUERY));
    }

    public function testIgnoredPropertiesStayUndocumented(): void
    {
        foreach ([Parameter::RESPONSE, Parameter::BODY, Parameter::QUERY] as $scope) {
            $this->assertNotContains('ignoredProperty', $this->documentedPropertyNames($scope), "scope $scope");
        }
    }
}
