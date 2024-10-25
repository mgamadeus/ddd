<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Pathes;

use DDD\Domain\Base\Entities\QueryOptions\ExpandDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Presentation\Base\OpenApi\Components\SchemaProperty;
use DDD\Presentation\Base\OpenApi\Exceptions\TypeDefinitionMissingOrWrong;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptionsTrait;
use ReflectionNamedType;
use ReflectionUnionType;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

class PathParameterSchema
{
    use SerializerTrait;

    public ?string $type = null;
    public ?string $format = null;
    public ?int $minLength;
    public ?int $maxLength;
    public ?string $pattern = null;
    /** @var array|null */
    public ?array $oneOf = null;
    public ?array $enum = null;
    private array $typeNameAllocation = [
        'int' => 'integer',
        'string' => 'string',
        'bool' => 'boolean',
        'float' => 'number',
        'array' => 'array'
    ];

    /**
     * @param PathParameter $parameter
     * @param ReflectionClass $requestDtoReflectionClass
     * @param ReflectionProperty $requestDtoReflectionProperty
     * @throws TypeDefinitionMissingOrWrong
     */
    public function __construct(
        PathParameter &$parameter,
        ReflectionClass &$requestDtoReflectionClass,
        ReflectionProperty &$requestDtoReflectionProperty
    ) {
        if (!$requestDtoReflectionProperty->getType()) {
            throw new TypeDefinitionMissingOrWrong(
                'Type Definition Missing in ' . $requestDtoReflectionClass->getName(
                ) . '->$' . $requestDtoReflectionProperty->getName()
            );
        }
        $type = $requestDtoReflectionProperty->getType();
        /** @var ReflectionNamedType[] $types */
        $types = [];
        $unionType = false;
        if ($requestDtoReflectionProperty->getType() instanceof ReflectionUnionType) {
            $types = $requestDtoReflectionProperty->getType()->getTypes();
            $unionType = true;
        } else {
            $types[] = $requestDtoReflectionProperty->getType();
        }
        foreach ($types as $type) {
            if ($type) {
                $typeName = $type->getName();
                if ($type->isBuiltin()) {
                    $typeNameAllocated = $this->typeNameAllocation[$typeName];
                    if ($typeNameAllocated == 'array') {
                        throw new TypeDefinitionMissingOrWrong(
                            'Declared type array in ' . $requestDtoReflectionClass->getName(
                            ) . '->$' . $requestDtoReflectionProperty->getName() . ' allowed only for BODY parameters'
                        );
                    }
                    if ($typeNameAllocated == 'object') {
                        if (!method_exists($typeName, 'fromString')) {
                            throw new TypeDefinitionMissingOrWrong(
                                'Declared type object in ' . $requestDtoReflectionClass->getName(
                                ) . '->$' . $requestDtoReflectionProperty->getName(
                                ) . ' not allowed. Only basic types are allowed outside of Body or Post, or Classes with fromString Method'
                            );
                        }
                    }
                    if ($unionType) {
                        $this->oneOf[] = ['type' => $typeNameAllocated];
                    } else {
                        $this->type = $typeNameAllocated;
                    }
                    if ($this->type == 'string') {
                        if ($lengthAttribute = $requestDtoReflectionProperty->getAttributes(Length::class)[0] ?? null) {
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

                    if (($attributes = $requestDtoReflectionProperty->getAttributes(Choice::class))
                        || ($attributes = $requestDtoReflectionProperty->getAttributes(
                            \DDD\Infrastructure\Validation\Constraints\Choice::class
                        ))
                    ) {
                        /** @var Choice $choiceAttribute */
                        $choiceAttribute = $attributes[0]->newInstance();
                        $this->enum = $choiceAttribute->choices;
                        $choicesAssoc = [];
                        $choicesDescripton = '';
                        $constantDescriptions = $requestDtoReflectionClass->getConstantsDescriptions();
                        foreach ($choiceAttribute->choices as $choice) {
                            $choicesAssoc[$choice] = true;
                            $constantDescription = $constantDescriptions[$choice] ?? '';
                            $choicesDescripton .= "-   `{$choice}`" . ($constantDescription ? ': ' . $constantDescription : '') . "\n";
                        }
                        if ($choicesDescripton) {
                            $parameter->description .= "  \nAllowed Values:  \n" . $choicesDescripton;
                        }
                    }
                } else {
                    //DateTime and Date are handled custom
                    if ($typeName == DateTime::class || $typeName == Date::class) {
                        $this->type = 'string';
                        $this->format = $type->getName(
                        ) == Date::class ? SchemaProperty::FORMAT_DATE : SchemaProperty::FORMAT_DATE_TIME;
                        continue;
                    } //QueryOptions custom handling and individual documentation
                    elseif ($typeName == FiltersOptions::class || $typeName == OrderByOptions::class || $typeName == ExpandOptions::class) {
                        $this->type = 'string';
                        $schemaReflectionClassName = $requestDtoReflectionClass->getName();

                        /** @var DtoQueryOptionsTrait $schemaReflectionClassName */
                        /** @var QueryOptions $queryOptions */
                        $dtoQueryOptions = $schemaReflectionClassName::getDtoQueryOptions();
                        if (!$dtoQueryOptions) {
                            throw new TypeDefinitionMissingOrWrong(
                                'Class ' . $requestDtoReflectionClass->getName(
                                ) . ' uses QueryOptions without having an attribute of type ' . DtoQueryOptions::class
                            );
                        }
                        $queryOptions = $dtoQueryOptions->getQueryOptions();
                        if (!$queryOptions) {
                            throw new TypeDefinitionMissingOrWrong(
                                "Class {$requestDtoReflectionClass->getName()} uses QueryOptions with a reference to base entity {$dtoQueryOptions->baseEntity}, but base entity has no QueryOptions attribute set on class"
                            );
                        }
                        // Special handling for Filters
                        if ($typeName == FiltersOptions::class) {
                            if (!$queryOptions->getFiltersDefinitions()) {
                                $parameter->setToBeSkipped(true);
                                continue;
                            }
                            $this->pattern = FiltersOptions::getRegexForOpenApi();
                            $parameter->description .= "\n\n<details><summary>Allowed filter properties:</summary>  \n\n";
                            $queryOptionsBaseReflectionClass = ReflectionClass::instance($dtoQueryOptions->baseEntity);
                            $constantDescriptions = $queryOptionsBaseReflectionClass->getConstantsDescriptions();

                            foreach ($queryOptions?->getFiltersDefinitions()?->getElements() as $allowedField) {
                                $constantDescription = $constantDescriptions[$allowedField->propertyName] ?? '';
                                $parameter->description .= "\n- `{$allowedField->propertyName}`" . ($constantDescription ? ': ' . $constantDescription : '');
                                if ($allowedField->options) {
                                    $parameter->description .= ' - one of [';
                                    $i = 0;
                                    foreach ($allowedField->options as $option) {
                                        $parameter->description .= ($i ? ', ' : '') . '`' . $option . '`';
                                        $i++;
                                    }
                                    $parameter->description .= ']';
                                }
                            }
                            $parameter->description .= "</details>";
                        } elseif ($typeName == OrderByOptions::class) {
                            if (!$queryOptions->getOrderByDefinitions()) {
                                $parameter->setToBeSkipped(true);
                                continue;
                            }
                            $this->pattern = OrderByOptions::getRegexForOpenApi();
                            $parameter->description .= "\n\n<details><summary>Allowed orderBy properties:</summary>  \n\n";
                            foreach ($queryOptions->getOrderByDefinitions() as $allowedField) {
                                $parameter->description .= "\n- `{$allowedField}`";
                            }
                            $parameter->description .= "</details>";
                        } elseif ($typeName == ExpandOptions::class) {
                            /** @var DtoQueryOptions $dtoQueryOptionsAttributeInstane */
                            $dtoQueryOptionsAttributeInstane = $schemaReflectionClassName::getDtoQueryOptions();
                            $baseEntityReflectionClass = ReflectionClass::instance(
                                $dtoQueryOptionsAttributeInstane->baseEntity
                            );

                            $expandDefinitions = ExpandDefinitions::getExpandDefinitionsForReferenceClass(
                                $dtoQueryOptionsAttributeInstane->baseEntity
                            );
                            if (!($expandDefinitions && $expandDefinitions->count())) {
                                $parameter->setToBeSkipped(true);
                                continue;
                            }
                            //$this->pattern = $queryOptions->orderBy->getRegexForOpenApi();
                            $parameter->description .= "\n\n<details><summary>Allowed expand properties:</summary>  \n\n";

                            foreach ($expandDefinitions->getElements() as $expandDefinition) {
                                $parameter->description .= "\n- `{$expandDefinition->propertyName}`";
                                if ($expandDefinition->getFiltersDefinitions()) {
                                    $parameter->description .= "\n  - Allowed filter properties are:";
                                    foreach ($expandDefinition->getFiltersDefinitions()->getElements() as $allowedField) {
                                        $parameter->description .= "\n    - `{$allowedField->propertyName}`";
                                        if ($allowedField->options) {
                                            $parameter->description .= ': one of [';
                                            $i = 0;
                                            foreach ($allowedField->options as $option) {
                                                $parameter->description .= ($i ? ', ' : '') . '`' . $option . '`';
                                                $i++;
                                            }
                                            $parameter->description .= ']';
                                        }
                                    }
                                }
                                if ($expandDefinition->getOrderbyDefinitions()) {
                                    $parameter->description .= "\n  - Allowed orderBy properties are:";
                                    foreach ($expandDefinition->getOrderbyDefinitions() as $allowedField) {
                                        $parameter->description .= "\n    - `{$allowedField}`";
                                    }
                                }
                            }
                            $parameter->description .= "</details>";
                        }
                        continue;
                    }
                    if (!method_exists($typeName, 'fromString')) {
                        throw new TypeDefinitionMissingOrWrong(
                            'Declared type ' . $typeName . ' in ' . $requestDtoReflectionClass->getName(
                            ) . '->$' . $requestDtoReflectionProperty->getName(
                            ) . ' not allowed. Only basic types are allowed outside of Body or Post'
                        );
                    }
                }
            }
        }
    }
}