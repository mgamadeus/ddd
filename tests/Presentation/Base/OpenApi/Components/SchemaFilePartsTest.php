<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Base\OpenApi\Components;

use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\OpenApi\Components\Schema;
use DDD\Tests\Presentation\Base\OpenApi\Components\Fixtures\FilePartsProbeRequestDto;
use PHPUnit\Framework\TestCase;

class SchemaFilePartsTest extends TestCase
{
    protected function buildFilesSchema(): Schema
    {
        $classWithNamespace = new ClassWithNamespace(FilePartsProbeRequestDto::class);
        $schema = new Schema($classWithNamespace, Parameter::FILES);
        $schema->buildSchema();
        return $schema;
    }

    public function testAFilePartIsDocumentedAsBinaryWhateverItsElementClass(): void
    {
        // no Document exists here: registering a schema component for the upload class would fail the test
        $schema = $this->buildFilesSchema();
        foreach (['attachmentFiles', 'fileList'] as $propertyName) {
            $this->assertArrayHasKey($propertyName, $schema->properties);
            $this->assertSame('array', $schema->properties[$propertyName]->type, $propertyName);
            $this->assertSame(['type' => 'string', 'format' => 'binary'], $schema->properties[$propertyName]->items, $propertyName);
        }
        $this->assertSame(['fileList'], $schema->required);
    }

    public function testTheMultipartSchemaHoldsOnlyTheFileParts(): void
    {
        $this->assertArrayNotHasKey('caption', $this->buildFilesSchema()->properties);
    }
}
