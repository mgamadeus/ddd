<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Pathes;

use DDD\Infrastructure\Reflection\ReflectionDocComment;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\OpenApi\Exceptions\TypeDefinitionMissingOrWrong;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Routing\Route;

class PathParameter
{
    use SerializerTrait;

    public ?string $name = null;
    public ?string $description = null;
    public ?string $example = null;
    public ?array $examples = null;
    public ?bool $required = null;
    public string $in = 'query';
    protected bool $toBeSkipped = false;
    protected ?Route $route;
    public ?PathParameterSchema $schema = null;

    /**
     * See [`SchemaProperty::normalizeExamplesForOpenApi()`](backend/vendor/mgamadeus/ddd/src/Presentation/Base/OpenApi/Components/SchemaProperty.php:86).
     *
     * @param string[] $examples
     * @return array
     */
    private function normalizeExamplesForOpenApi(array $examples): array
    {
        if (!$examples) {
            return $examples;
        }

        $keys = array_keys($examples);
        $isList = $keys === range(0, count($examples) - 1);
        if (!$isList) {
            return $examples;
        }

        $normalized = [];
        foreach ($examples as $index => $example) {
            $exampleIndex = (string)($index + 1);
            $normalized[$exampleIndex] = [
                'summary' => $exampleIndex,
                'value' => $example,
            ];
        }
        return $normalized;
    }

    /**
     * @param ReflectionClass $requestDtoReflectionClass
     * @param ReflectionProperty $requestDtoReflectionProperty
     * @throws TypeDefinitionMissingOrWrong
     */
    public function __construct(
        ReflectionClass &$requestDtoReflectionClass,
        ReflectionProperty &$requestDtoReflectionProperty,
        ?Route $route = null
    ) {
        $this->route = $route;
        $this->name = $requestDtoReflectionProperty->getName();
        if ($requestDtoReflectionProperty->getDocComment()) {
            $docComment = new ReflectionDocComment($requestDtoReflectionProperty->getDocComment());
            $this->description = $docComment->getDescription(true, false);
            $examples = $docComment->getExamples();
            if (count($examples) > 1) {
                $this->examples = $this->normalizeExamplesForOpenApi($examples);
            }
            elseif ($examples) {
                $this->example = $examples[0];
            }
        }
        foreach ($requestDtoReflectionProperty->getAttributes() as $requestDtoPropertyAttribute) {
            $requestDtoPropertyAttributeInstance = $requestDtoPropertyAttribute->newInstance();
            if ($requestDtoPropertyAttributeInstance instanceof Parameter) {
                $this->in = $requestDtoPropertyAttributeInstance->in;
                // we check if the path contains parameter, if so, the parameter is required
                if ($this->in == Parameter::PATH && $route) {
                    $routePathParamer = $route->compile()->getPathVariables() ?? [];
                    if (in_array($this->name, $routePathParamer)) {
                        $this->required = true;
                    }
                    else {
                        // in some cases we have routes that exist with and without a certain path parameter e.g. /accounts/{accountId}/update and account/update
                        // for these cases we often use the same requerstDto, so we need to skip corresponding parameters if they are not part of the path in the current route
                        $this->setToBeSkipped(true);
                    }
                } else {
                    $this->required = $requestDtoPropertyAttributeInstance->required ?? false;
                }
            }
        }
        if (!in_array($this->in,[Parameter::BODY, Parameter::POST, Parameter::FILES])) {
            $this->schema = new PathParameterSchema($this, $requestDtoReflectionClass, $requestDtoReflectionProperty);
        }
    }

    /**
     * @return bool
     */
    public function isToBeSkipped(): bool
    {
        return $this->toBeSkipped;
    }

    /**
     * @param bool $toBeSkipped
     */
    public function setToBeSkipped(bool $toBeSkipped): void
    {
        $this->toBeSkipped = $toBeSkipped;
    }
}
