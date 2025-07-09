<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Components;

use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use ReflectionProperty;

class Schema
{
    use SerializerTrait;

    /** @var string type of schema is always project */
    public string $type = 'object';

    /**
     * Format of the schema, e.g. binary
     * @var string
     */
    public ?string $format;

    /** @var SchemaProperty[] schema properties */
    public array|object $properties = [];

    /** @var string[] stores all required fields */
    public array $required;

    /** @var ClassWithNamespace encapsulates schmea class */
    private ?ClassWithNamespace $schemaClass;
    /**
     * Either used for Body, or Post
     * @var string
     */
    private ?string $scope;

    public function __construct(
        ClassWithNamespace &$schemaClass = null,
        ?string $scope = null
    ) {
        $this->schemaClass = $schemaClass;
        $this->scope = $scope;
    }

    public function buildSchema()
    {
        if ($this->schemaClass) {
            $schemReflectionClass = new ReflectionClass($this->schemaClass->getNameWithNamespace());
            foreach ($schemReflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
                // we skip properties that are ment to be hidden and static properties as well
                if ($reflectionProperty->isStatic()) {
                    continue;
                }
                //we document entities, entityDtos and request / response DTO classes in the same way
                //in case of request DTOs we need to skip all properties that are not passed in the BODY, POST or FILES,
                //e.g. query or path assigned properties
                //as request DTOs have Attributes on their properties of type Parameter we can rely on the
                //"in" property of the Parameter attribute (in can be in PATH, QUERY, BODY etc)
                $skipProperty = false;
                foreach ($reflectionProperty->getAttributes(Parameter::class) as $attribute) {
                    /** @var Parameter $parameterAttributeInstance */
                    $parameterAttributeInstance = $attribute->newInstance();
                    if ($parameterAttributeInstance->in != $this->scope && $this->scope != Parameter::RESPONSE) {
                        $skipProperty = true;
                        break;
                    }
                }
                if ($skipProperty) {
                    continue;
                }
                $this->properties[$reflectionProperty->getName()] = new SchemaProperty(
                    $schemReflectionClass, $reflectionProperty, $this->scope, $this
                );
            }
        }
        if (!count($this->properties)) {
            $this->properties = (object)[];
        }
    }

    /**
     * adds property name to required list
     * @param string $propertyName
     * @return void
     */
    public function addRequiredProperty(string $propertyName)
    {
        if (!isset($this->required)) {
            $this->required = [];
        }
        $this->required[] = $propertyName;
    }
}