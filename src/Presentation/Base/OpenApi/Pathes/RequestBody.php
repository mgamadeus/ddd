<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Pathes;

use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\OpenApi\Exceptions\TypeDefinitionMissingOrWrong;
use ReflectionProperty;

class RequestBody
{
    use SerializerTrait;

    public string $description = '';
    public bool $required = false;
    /** @var RequestBodySchema[][] */
    public array $content = [];

    protected $hasBodyParameters = false;
    protected $hasPostParameters = false;
    protected $hasFileParameters = false;

    /**
     * @param ReflectionClass $requestDtoReflectionClass
     * @throws TypeDefinitionMissingOrWrong
     */
    public function __construct(ReflectionClass &$requestDtoReflectionClass)
    {
        foreach (
            $requestDtoReflectionClass->getProperties(
                ReflectionProperty::IS_PUBLIC
            ) as $requestDtoReflectionProperty
        ) {
            //determine if we have body / post parameters and based on this set application/json and so on
            $pathParameter = new PathParameter($requestDtoReflectionClass, $requestDtoReflectionProperty);
            if ($pathParameter->in == Parameter::BODY) {
                $this->hasBodyParameters = true;
            }
            if ($pathParameter->in == Parameter::POST) {
                $this->hasPostParameters = true;
            }
            if ($pathParameter->in == Parameter::FILES) {
                $this->hasFileParameters = true;
            }
        }
        if ($this->hasBodyParameters) {
            $this->content['application/json'] = new RequestBodySchema($requestDtoReflectionClass, Parameter::BODY);
        }
        if ($this->hasPostParameters) {
            $this->content['application/x-www-form-urlencoded'] = new RequestBodySchema(
                $requestDtoReflectionClass, Parameter::POST
            );
        }
        if ($this->hasFileParameters) {
            $this->content['multipart/form-data'] = new RequestBodySchema($requestDtoReflectionClass, Parameter::FILES);
        }
    }

    public function hasContent():bool
    {
        return $this->hasPostParameters || $this->hasBodyParameters || $this->hasFileParameters;
    }
}