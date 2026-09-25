<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Components;

use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Presentation\Base\OpenApi\Attributes\Ignore;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use ReflectionAttribute;
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
    protected ?ClassWithNamespace $schemaClass;

    /**
     * Either used for Body, or Post
     * @var string
     */
    protected ?string $scope;

    public function __construct(
        ClassWithNamespace &$schemaClass = null,
        ?string $scope = null
    )
    {
        $this->schemaClass = $schemaClass;
        $this->scope = $scope;
    }

    public function buildSchema(?int $maxRecursiveSchemaDepth = null)
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
                if ($reflectionProperty->hasAttribute(Ignore::class)) {
                    $skipProperty = true;
                }
                foreach (
                    $reflectionProperty->getAttributes(
                        Parameter::class,
                        ReflectionAttribute::IS_INSTANCEOF
                    ) as $attribute
                ) {
                    /** @var Parameter $parameterAttributeInstance */
                    $parameterAttributeInstance = $attribute->newInstance();
                    // A property declared as a RESPONSE field is documented in EVERY scope of this class' schema.
                    // Components are keyed by class name alone ({@see Components::addSchemaForClass()}, first
                    // registration wins) and a class reached as a nested property is registered with the default
                    // BODY scope, so without this a `#[Parameter(in: Parameter::RESPONSE)]` property vanished from
                    // the documentation altogether whenever anything but the response path registered the class
                    // first — annotating a response field made it disappear, the exact opposite of the intent.
                    if (
                        $parameterAttributeInstance->in != $this->scope
                        && $this->scope != Parameter::RESPONSE
                        && $parameterAttributeInstance->in != Parameter::RESPONSE
                    ) {
                        $skipProperty = true;
                        break;
                    }
                }
                if ($skipProperty) {
                    continue;
                }
                $this->properties[$reflectionProperty->getName()] = new SchemaProperty(
                    $schemReflectionClass, $reflectionProperty, $this->scope, $this, $maxRecursiveSchemaDepth
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