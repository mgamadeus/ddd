<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Components;

use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionArrayType;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionDocComment;
use DDD\Infrastructure\Reflection\ReflectionUnionType;
use DDD\Infrastructure\Traits\Serializer\Attributes\OverwritePropertyName;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Presentation\Base\OpenApi\Attributes\ClassName;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\OpenApi\Attributes\Required;
use DDD\Presentation\Base\OpenApi\Document;
use DDD\Presentation\Base\OpenApi\Exceptions\TypeDefinitionMissingOrWrong;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

class SchemaProperty
{
    use SerializerTrait;

    public const FORMAT_DATE_TIME = 'date-time';
    public const FORMAT_DATE = 'date';
    public ?string $type = null;
    public ?array $enum = null;
    public ?string $format = null;
    public ?string $example = null;
    public ?int $minLength;
    public ?int $maxLength;
    public mixed $default = null;
    public ?string $description = null;
    #[OverwritePropertyName('$ref')]
    public ?string $ref = null;
    /** @var string[]|null */
    public ?array $oneOf = null;
    public array|object|null $items = null;
    private Schema $schema;
    private array $typeNameAllocation = [
        'int' => 'integer',
        'string' => 'string',
        'bool' => 'boolean',
        'float' => 'number',
        'array' => 'array',
        'object' => 'object'
    ];
    private string $scope = Parameter::BODY;

    /**
     * @param ReflectionClass $schemaReflectionClass
     * @param ReflectionProperty $schemaClassReflectionProperty
     * @param string $scope
     * @throws TypeDefinitionMissingOrWrong
     * @throws ReflectionException
     */
    public function __construct(
        ReflectionClass &$schemaReflectionClass,
        ReflectionProperty &$schemaClassReflectionProperty,
        string $scope = Parameter::BODY,
        Schema &$schema = null
    ) {
        $this->schema = $schema;

        if (!$schemaClassReflectionProperty->getType()) {
            throw new TypeDefinitionMissingOrWrong(
                'Type Definition Missing in ' . $schemaReflectionClass->getName(
                ) . '->$' . $schemaClassReflectionProperty->getName()
            );
        }

        if ($schemaClassReflectionProperty->getDocComment()) {
            $docComment = new ReflectionDocComment($schemaClassReflectionProperty->getDocComment());
            if ($description = $docComment->getDescription(true)) {
                $this->description = $description;
            }
            if ($example = $docComment->getExample()) {
                $this->example = $example;
            }
        }

        /** @var ReflectionNamedType[] $types */
        $types = [];
        // we have a union type e.g. ClassA|ClassB
        $unionType = false;

        /* Used for Debug to stop at particular class
        if ($schemaReflectionClass->getName() == KeywordDomainsLocationsRankings::class && $schemaClassReflectionProperty->getName() == 'locations'){
            echo "asd";
        }*/

        if ($schemaClassReflectionProperty->getType() instanceof \ReflectionUnionType) {
            $types = $schemaClassReflectionProperty->getType()->getTypes();
            $unionType = true;
        } else {
            $types[] = $schemaClassReflectionProperty->getType();
        }
        foreach ($types as $type) {
            /** @var ReflectionNamedType $type */
            // null type is ignored
            if ($type->getName() == 'null') {
                continue;
            }
            $required = false;

            if (
                $schemaClassReflectionProperty->getAttributes(
                    Required::class
                ) || $schemaClassReflectionProperty->getAttributes(NotNull::class)
            ) {
                $required = true;
            }
            foreach ($schemaClassReflectionProperty->getAttributes(Parameter::class) as $attribute) {
                /** @var Parameter $parameterAttributeInstance */
                $parameterAttributeInstance = $attribute->newInstance();
                if ($parameterAttributeInstance->isRequired()) {
                    $required = true;
                    break;
                }
            }
            if ($required) {
                $this->schema->addRequiredProperty($schemaClassReflectionProperty->getName());
            }
            if ($type->isBuiltin()) {
                // array types are not accepted in case of POST scenario, only in BODY
                if ($this->type == 'array' && $scope == Parameter::POST) {
                    throw new TypeDefinitionMissingOrWrong(
                        'Declared type array in ' . $schemaReflectionClass->getName(
                        ) . '->$' . $schemaClassReflectionProperty->getName() . ' allowed only for BODY parameters'
                    );
                }
                if ($this->type == 'object') {
                    throw new TypeDefinitionMissingOrWrong(
                        'Declared type object in ' . $schemaReflectionClass->getName(
                        ) . '->$' . $schemaClassReflectionProperty->getName(
                        ) . ' not allowed. Use array or create a class'
                    );
                }

                // handle Choice attributes (fixed set of options for the property)
                if (
                    ($attributes = $schemaClassReflectionProperty->getAttributes(Choice::class)) ||
                    ($attributes = $schemaClassReflectionProperty->getAttributes(
                        \DDD\Infrastructure\Validation\Constraints\Choice::class
                    ))
                ) {
                    /** @var Choice $choiceAttribute */
                    $choiceAttribute = $attributes[0]->newInstance();
                    $this->enum = $choiceAttribute->choices;
                    $choicesAssoc = [];
                    $choicesDescripton = '';
                    $constantDescriptions = $schemaReflectionClass->getConstantsDescriptions();
                    foreach ($choiceAttribute->choices as $choice) {
                        $choicesAssoc[$choice] = true;
                        $constantDescription = $constantDescriptions[$choice] ?? '';
                        $choicesDescripton .= "-   `{$choice}`" . ($constantDescription ? ': ' . $constantDescription : '') . "\n";
                    }
                    if ($choicesDescripton) {
                        $this->description .= "  \n Allowed Values:  \n" . $choicesDescripton;
                    }
                }
                // this is to handle e.g. objectType attriubte, which has only one enum option, the class Name
                if ($this->type == 'string') {
                    if ($schemaClassReflectionProperty->getAttributes(ClassName::class)) {
                        $this->enum[] = $schemaReflectionClass->getName();
                        // objectType => className is always required by convention
                        $this->schema->addRequiredProperty($schemaClassReflectionProperty->getName());
                    }
                    if ($lengthAttribute = $schemaClassReflectionProperty->getAttributes(Length::class)[0] ?? null) {
                        /** @var Length $lengthAttributeInstance */
                        $lengthAttributeInstance = $lengthAttribute->newInstance();
                        if ($lengthAttributeInstance->min) {
                            $this->minLength = $lengthAttributeInstance->min;
                        }
                        if ($lengthAttributeInstance->max) {
                            $this->maxLength = $lengthAttributeInstance->max;
                        }
                    }
                }
                if (
                    $this->type != 'array' && $this->type != 'object' && $schemaClassReflectionProperty->hasDefaultValue(
                    )
                ) {
                    $this->default = $schemaClassReflectionProperty->getDefaultValue();
                }

                if ($unionType) {
                    if (!$this->oneOf) {
                        $this->oneOf = [];
                    }
                    $this->oneOf[] = ['type' => $this->typeNameAllocation[$type->getName()]];
                } // in standard type case we have normal $ref
                else {
                    $this->type = $this->typeNameAllocation[$type->getName()];
                }

                if ($this->type == 'array' && ($scope == Parameter::BODY || $scope == Parameter::RESPONSE)) {
                    $arrayTypes = [];
                    /** @var ReflectionArrayType $type */
                    $arrayType = $type->getArrayType();
                    if (!$arrayType) {
                        throw new TypeDefinitionMissingOrWrong(
                            'Array Type Definition Missing in ' . $schemaReflectionClass->getName(
                            ) . '->$' . $schemaClassReflectionProperty->getName()
                        );
                    }
                    if ($arrayType instanceof ReflectionUnionType) {
                        foreach ($type->getArrayType()->getTypes() as $namedType) {
                            $arrayTypes[] = $namedType->getName();
                        }
                    } else {
                        $arrayTypes[] = $arrayType->getName();
                    }

                    $unionType = count($arrayTypes) > 1;
                    if ($arrayTypes) {
                        $this->items = [];
                        if ($unionType) {
                            $this->items['oneOf'] = [];
                        }
                        foreach ($arrayTypes as $arrayType) {
                            // we have a base type (excluding array)
                            if (isset($this->typeNameAllocation[$arrayType])) {
                                // we cannot have simply array[], we need a base or complex type defined
                                /*if ($arrayType == 'array') {
                                    throw new TypeDefinitionMissingOrWrong(
                                        'Declared type array[] in ' . $schemaReflectionClass->getName(
                                        ) . '->$' . $schemaClassReflectionProperty->getName(
                                        ) . ' is not allowed. Array needs to be specified either as array of some basetype, e.g. string[] or as array of ComplexClass[]'
                                    );
                                }*/
                                if ($unionType) {
                                    $this->items['oneOf'][] = ['type' => $this->typeNameAllocation[$arrayType]];
                                } elseif ($arrayType == 'array') {
                                    // in case of unspecified array types, we let type generic
                                    $this->items = (object)[];
                                } else {
                                    $this->items['type'] = $this->typeNameAllocation[$arrayType];
                                }
                            } // we have a complex type
                            else {
                                $propertyClass = new ClassWithNamespace($arrayType);
                                if (!class_exists($propertyClass->getNameWithNamespace())) {
                                    //echo $propertyClass->getNameWithNamespace();die();
                                    throw new TypeDefinitionMissingOrWrong(
                                        'Declared type class ' . $propertyClass->getNameWithNamespace(
                                        ) . ' in ' . $schemaReflectionClass->getName(
                                        ) . '->$' . $schemaClassReflectionProperty->getName() . ' does not exist'
                                    );
                                }
                                if ($unionType) {
                                    $this->items['oneOf'][] = [
                                        '$ref' => '#/components/schemas/' . $propertyClass->getNameWithNamespace(
                                                '.'
                                            )
                                    ];
                                } else {
                                    $this->items['$ref'] = '#/components/schemas/' . $propertyClass->getNameWithNamespace(
                                            '.'
                                        );
                                }
                                Document::getInstance()->components->addSchemaForClass($propertyClass);
                            }
                        }
                    }
                }
            } else {
                //DateTime and Date are handled custom
                if ($type->getName() == DateTime::class || $type->getName() == Date::class) {
                    $this->type = 'string';
                    $this->format = $type->getName() == Date::class ? self::FORMAT_DATE : self::FORMAT_DATE_TIME;
                    continue;
                }

                // complex types are not accepted in POST scenario
                if ($scope == Parameter::POST) {
                    throw new TypeDefinitionMissingOrWrong(
                        'Declared type ' . $type->getName() . ' in ' . $schemaReflectionClass->getName(
                        ) . '->$' . $schemaClassReflectionProperty->getName() . ' allowed only for BODY parameters'
                    );
                } else {
                    $this->type = 'object';
                    $propertyClass = new ClassWithNamespace($type->getName());
                    // in case of Union Type we have oneOf and multiple $ref in an array below oneOf
                    if ($unionType) {
                        if (!$this->oneOf) {
                            $this->oneOf = [];
                        }
                        $this->oneOf[] = [
                            '$ref' => '#/components/schemas/' . $propertyClass->getNameWithNamespace(
                                    '.'
                                )
                        ];
                    } // in standard type case we have normal $ref
                    else {
                        // since all other properties are ignored if $ref is ued, we null them
                        $this->description = null;
                        $this->type = null;
                        $this->ref = '#/components/schemas/' . $propertyClass->getNameWithNamespace('.');
                    }
                    if (!class_exists($propertyClass->getNameWithNamespace())) {
                        throw new TypeDefinitionMissingOrWrong(
                            'Declared type class ' . $propertyClass->getNameWithNamespace(
                            ) . ' in ' . $schemaReflectionClass->getName(
                            ) . '->$' . $schemaClassReflectionProperty->getName() . ' does not exist'
                        );
                    }
                    Document::getInstance()->components->addSchemaForClass($propertyClass);
                }
            }
        }
    }
}