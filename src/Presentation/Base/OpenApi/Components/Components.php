<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Components;

use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Presentation\Base\OpenApi\Document;

class Components
{
    use SerializerTrait;

    /** @var Schema[] */
    public array $schemas = [];
    protected Document $document;

    public function __construct(Document &$document)
    {
        $this->document = $document;
    }


    public function addSchemaForClass(
        ClassWithNamespace &$classWithNamespace,
        string $scope = Parameter::BODY
    ) {
        $classNamespaceWithDots = $classWithNamespace->getNameWithNamespace('.');
        if (isset($this->schemas[$classNamespaceWithDots])) {
            return;
        }

        //redocly compatible Schema Tags
        $schemaDesription = '<SchemaDefinition schemaRef="#/components/schemas/'.$classNamespaceWithDots.'" />';
        $schemaTag = new Tag($classWithNamespace->name, 'Models',$schemaDesription);
        $this->document->addGlobalTag($schemaTag);


        // add schema
        $schema = new Schema($classWithNamespace, $scope);
        $this->schemas[$classNamespaceWithDots] = $schema;
        // !!!ATTENTION - LET THIS LINE AT THE END SEPARATED!!!
        // This method cannot by called in constructor and has to be executed after we add the schema to the schmeas array
        //otherwise the initial check if the schema is already present will fail in recurisve calls
        //(as schema can contain opther schemas and at some point a schema of itself,
        //e.g. OrmAccount->parentAccount is also of type OrmAccount)
        $schema->buildSchema();
    }
}