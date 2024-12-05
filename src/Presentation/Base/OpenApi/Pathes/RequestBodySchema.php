<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Pathes;

use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\OpenApi\Components\Schema;
use DDD\Presentation\Base\OpenApi\Document;

class RequestBodySchema
{
    use SerializerTrait;

    public array|Schema $schema = ['$ref' => ''];
    private string $scope = Parameter::BODY;

    public function __construct(
        ReflectionClass &$requestDtoReflectionClass,
        string $scope = Parameter::BODY
    ) {
        $this->scope = $scope;
        $classWithNamespace = new ClassWithNamespace($requestDtoReflectionClass->getName());
        // in case of request BODY we add schema with $ref to components
        // we are assuming here a complex potentially recursive schema definition
        if ($this->scope == Parameter::BODY) {
            $this->schema['$ref'] = '#/components/schemas/' . $classWithNamespace->getNameWithNamespace('.');
            Document::getInstance()->components->addSchemaForClass($classWithNamespace, $scope);
        }
        // in case of request POST or FILES form data we add schema with $ref to components
        // we are assuming here a complex potentially recursive schema definition
        if (in_array($this->scope, [Parameter::POST, Parameter::FILES])) {
            $schema = new Schema($classWithNamespace, $scope);
            $schema->buildSchema();
            $this->schema = $schema;
        }
    }
}
