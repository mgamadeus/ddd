<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Pathes;

use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\Exception;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionDocComment;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Presentation\Base\Controller\BaseController;
use DDD\Presentation\Base\Controller\Filters\Before;
use DDD\Presentation\Base\Dtos\RedirectResponseDto;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Presentation\Base\OpenApi\Document;
use DDD\Presentation\Base\OpenApi\Exceptions\TypeDefinitionMissingOrWrong;
use ReflectionAttribute;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;

/**
 * represents a OA Path for an API call it is constructed by a path route
 */
class Path
{
    use SerializerTrait;

    public string $summary = '';

    public string $description = '';

    /** @var PathParameter[] */
    public array $parameters = [];

    public ?RequestBody $requestBody = null;

    /** @var PathResponse[] */
    public ?array $responses = null;

    /** @var string[] */
    public ?array $tags = null;

    /** @var PathParameter[] */
    private array $parametersByName = [];

    /**
     * @param Route $route
     * @param string $httpMethod
     * @param ReflectionClass $controllerReflectionClass
     * @param ReflectionMethod $controllerReflectionMethod
     * @throws ReflectionException
     * @throws TypeDefinitionMissingOrWrong
     */
    public function __construct(
        Route &$route,
        string $httpMethod,
        ReflectionClass &$controllerReflectionClass,
        ReflectionMethod &$controllerReflectionMethod,
    ) {
        //add tags from controller class
        foreach ($controllerReflectionClass->getAttributes(Tag::class, ReflectionAttribute::IS_INSTANCEOF) as $controllerMethodAttribute) {
            /** @var Tag $tagAttribute */
            $tagAttribute = $controllerMethodAttribute->newInstance();
            $this->addTag($tagAttribute);
        }
        /** @var ClassWithNamespace[] $throwDeclarations */
        $throwDeclarations = [];
        $controllerMathodDocComment = $controllerReflectionMethod->getDocComment();
        if ($controllerMathodDocComment) {
            $docComment = new ReflectionDocComment($controllerMathodDocComment);
            $throwDeclarations = $docComment->getThrowDeclarations();
            $throwDeclarationsClasses = [];
            if ($throwDeclarations) {
                foreach ($throwDeclarations as $throwDeclaration) {
                    $throwDeclarationClass = $controllerReflectionClass->getClassWithNamespaceConsideringUseStatements(
                        $throwDeclaration
                    );
                    if ($throwDeclarationClass) {
                        $throwDeclarationsClasses[] = $throwDeclarationClass;
                    }
                }
            }
            $throwDeclarations = $throwDeclarationsClasses;
            $this->description = $docComment->getDescription();
        }

        //we check for attributes on the method in order to extract additonal information e.g. summary
        foreach ($controllerReflectionMethod->getAttributes() as $controllerMethodAttribute) {
            $controllerMethodAttributeInstance = $controllerMethodAttribute->newInstance();
            if ($controllerMethodAttributeInstance instanceof Summary) {
                $this->summary = $controllerMethodAttributeInstance->summary;
            }
        }

        // we use this variable in order to show BadRequest error resppnses, this only apploes to routes that accept some parameters or have a body
        $acceptsParameterOrBody = false;

        // we have two potential sources of path parameters
        // 1. are dtos passed to mathods with #before attributes
        // 2. are the dtos passed to the controller method
        $dtoClassNames = [];
        /** @var BaseController $controllerClass */
        $controllerClass = $controllerReflectionClass->getName();
        $beforeMethods = $controllerClass::getBeforeAndAfterMethods(Before::class);
        foreach ($beforeMethods as $beforeMethod) {
            $beforeMethodName = $beforeMethod->getName();
            $beforeMethodParameters = $beforeMethod->getParameters();
            foreach ($beforeMethodParameters as $beforeMethodParameter) {
                $type = $beforeMethodParameter->getType()->getName();
                if (is_a($type, RequestDto::class, true)) {
                    $dtoClassNames[] = $type;
                }
            }
        }
        foreach ($controllerReflectionMethod->getParameters() as $methodParameter) {
            $type = $methodParameter->getType()->getName();
            if (is_a($type, RequestDto::class, true)) {
                $dtoClassNames[] = $type;
            }
        }

        foreach ($dtoClassNames as $dtoClassName) {
            //we analyse the RequestDto Class and build properties and body
            $requestDtoReflectionClass = ReflectionClass::instance($dtoClassName);
            if (!$requestDtoReflectionClass) {
                continue;
            }
            foreach (
                $requestDtoReflectionClass->getProperties(
                    ReflectionProperty::IS_PUBLIC
                ) as $requestDtoReflectionProperty
            ) {
                $pathParameter = new PathParameter($requestDtoReflectionClass, $requestDtoReflectionProperty, $route);
                // for paramters of type path, validate if the path of the route contains the parameter definition
                $routePathParamer = $route->compile()->getPathVariables() ?? [];

                if (
                    $pathParameter->in == Parameter::PATH && $pathParameter->required && !in_array(
                        $pathParameter->name,
                        $routePathParamer
                    )
                ) {
                    throw new TypeDefinitionMissingOrWrong(
                        'Route Param {' . $pathParameter->name . '} defined in ' . $requestDtoReflectionClass->getName(
                        ) . ' missing in Route Path Definition for Route ' . $route->getPath(
                        ) . ':' . $httpMethod . ' (' . $controllerClass . '->' . $controllerReflectionMethod->getName() . ')'
                    );
                }
                // we add only non-body, non-files, non-post parameters as body,post and files parameters are described separately
                if (!in_array($pathParameter->in, [Parameter::BODY, Parameter::POST, Parameter::FILES]) && !$pathParameter->isToBeSkipped()) {
                    $this->addParamter($pathParameter);
                    $acceptsParameterOrBody = true;
                }
            }
            /*
            //iterate through all Path Paramters of the Route's path in order to check if they are specified in RequestDto
            foreach ($route->getRouteParams() as $routeParamName) {
                if (!$this->hasParamter($routeParamName)) {
                    throw new TypeDefinitionMissingOrWrong(
                        'Route param {' . $routeParamName . '} defined in Route Path Definition for Route ' . $route->getFullPath(
                        ) . ':' . $route->requestMethod . ' (Controller: ' . $route->controller . '->' . $route->controllerMethod . ') missing as property defined with in:path attribute in ' . $requestDtoReflectionClass->getName(
                        )
                    );
                }
            }*/

            // on get and delete requests we have no Request Body
            if ($httpMethod == Request::METHOD_GET) {
                unset($this->requestBody);
            } else {
                $requestBody = new RequestBody($requestDtoReflectionClass, $httpMethod);
                if ($requestBody->hasContent()) {
                    $this->requestBody = new RequestBody($requestDtoReflectionClass, $httpMethod);
                    if (
                        in_array(
                            $httpMethod,
                            [Request::METHOD_POST, Request::METHOD_PATCH, Request::METHOD_PUT]
                        )
                    ) {
                        $this->requestBody->required = true;
                        $acceptsParameterOrBody = true;
                    }
                }
            }
        }
        // generate Responses
        if ($controllerReflectionMethod->getReturnType()) {
            $responseDtoReflectionClass = new ReflectionClass($controllerReflectionMethod->getReturnType()->getName());
            if (is_a($responseDtoReflectionClass->getName(), RedirectResponseDto::class, true)) {
                $this->responses = [
                    RedirectResponseDto::DEFAULT_HTTP_CODE => new PathResponse(
                        $responseDtoReflectionClass
                    )
                ];
            } else {
                $this->responses = [200 => new PathResponse($responseDtoReflectionClass)];
            }
            // if route requires authentication, we also provide a Unauthorized response
            /*
            if ($route->requiresAuth()) {
                $exceptionReflectionClass = UnauthorizedException::getReflectionClass();
                $this->responses[Exception::CODE_UNAUTHORIZED] = new Response($exceptionReflectionClass);
            }*/
            // if we have throw clauses on the controller method, that extend Exception, we preovide the corresponding error responses
            if ($throwDeclarations) {
                foreach ($throwDeclarations as $throwDeclaration) {
                    $throwDeclarationClassName = $throwDeclaration->getNameWithNamespace();
                    // we document only classes of type exception as only for these we have a default error code (which we use as hhtp status code)
                    if (is_a($throwDeclarationClassName, Exception::class, true)) {
                        $exceptionReflectionClass = $throwDeclarationClassName::getReflectionClass();
                        $this->responses[$throwDeclarationClassName::getErrorCode()] = new PathResponse(
                            $exceptionReflectionClass
                        );
                    }
                }
            }
            // in case that we accept parameters of body, we will have a bad request response as well
            if ($acceptsParameterOrBody) {
                $exceptionReflectionClass = BadRequestException::getReflectionClass();
                $this->responses[Response::HTTP_BAD_REQUEST] = new PathResponse($exceptionReflectionClass);
            }
            // if we have throw clauses on the controller method, that extend Exception, we preovide the corresponding error responses
        } else {
            throw new TypeDefinitionMissingOrWrong(
                'No ResponeDto defined for Route ' . $route->getPath(
                ) . ':' . $httpMethod . ' (Controller: ' . $controllerClass . '->' . $controllerReflectionMethod->getName() . ')'
            );
        }

        if (!$this->summary && Document::THROW_ERRORS_ON_ENDPOINTS_WITHOUT_SUMMARY) {
            throw new TypeDefinitionMissingOrWrong(
                "No summary specified for Controller {$controllerClass}->{$controllerReflectionMethod->getName()}"
            );
        }
        if ($this->summary) {
            $summaryWords = count(explode(' ', $this->summary));
            $summaryChars = strlen($this->summary);
            if ($summaryWords > Document::MAX_SUMMARY_WORDS) {
                throw new TypeDefinitionMissingOrWrong(
                    'Summary exceeds ' . Document::MAX_SUMMARY_WORDS . " words on {$controllerClass}->{$controllerReflectionMethod->getName()} ({$this->summary})"
                );
            }
            if ($summaryChars > Document::MAX_SUMMARY_CHARS) {
                throw new TypeDefinitionMissingOrWrong(
                    'Summary exceeds ' . Document::MAX_SUMMARY_CHARS . " characters on {$controllerClass}->{$controllerReflectionMethod->getName()}"
                );
            }
        }
        if (!$this->description && Document::THROW_ERRORS_ON_ENDPOINTS_WITHOUT_DESCRIPTION) {
            throw new TypeDefinitionMissingOrWrong(
                "No description specified for Controller {$controllerClass}->{$controllerReflectionMethod->getName()}"
            );
        }
    }

    /**
     * adds global tag, completes data if tag is already present, does not overwrite data
     * @param Tag $tag
     * @return void
     */
    public function addTag(Tag &$tag): void
    {
        if (!$this->tags) {
            $this->tags = [];
        }
        $this->tags[] = $tag->name;
        $documentInstance = Document::getInstance();
        $documentInstance->addGlobalTag($tag);
    }

    public function addParamter(PathParameter &$paramter): void
    {
        if (isset($this->parametersByName[$paramter->name])) {
            return;
        }
        $this->parameters[] = $paramter;
        $this->parametersByName[$paramter->name] = $paramter;
    }

    /**
     * returns if paramter with $paramterName exists
     * @param string $paramterName
     * @return bool
     */
    public function hasParamter(string $paramterName): bool
    {
        return isset($this->parametersByName[$paramterName]);
    }
}