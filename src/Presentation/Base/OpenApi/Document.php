<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi;

use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Traits\Serializer\Attributes\OverwritePropertyName;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Presentation\Base\OpenApi\Attributes\Ignore;
use DDD\Presentation\Base\OpenApi\Attributes\Info;
use DDD\Presentation\Base\OpenApi\Attributes\OpenApi;
use DDD\Presentation\Base\OpenApi\Attributes\SecurityScheme;
use DDD\Presentation\Base\OpenApi\Attributes\Server;
use DDD\Presentation\Base\OpenApi\Attributes\Tag;
use DDD\Presentation\Base\OpenApi\Attributes\TagGroup;
use DDD\Presentation\Base\OpenApi\Attributes\TagGroups;
use DDD\Presentation\Base\OpenApi\Attributes\Tags;
use DDD\Presentation\Base\OpenApi\Components\Components;
use DDD\Presentation\Base\OpenApi\Exceptions\TypeDefinitionMissingOrWrong;
use DDD\Presentation\Base\OpenApi\Pathes\Path;
use ReflectionException;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Self generating Document class describing an API based on OpenApi Standard
 * The document itself is the API definition when serialized
 */
class Document
{
    use SerializerTrait;

    public const MAX_SUMMARY_WORDS = 7;
    public const MAX_SUMMARY_CHARS = 75;
    public const THROW_ERRORS_ON_ENDPOINTS_WITHOUT_DESCRIPTION = true;
    public const THROW_ERRORS_ON_ENDPOINTS_WITHOUT_SUMMARY = true;

    public const BASE_TYPES = ['string' => true, 'integer' => true, 'float' => true, 'boolean' => true];

    private static Document $instance;

    public string $openapi = '3.0.0';

    public ?Info $info = null;

    public ?SecurityScheme $securityScheme = null;

    /** @var Server[] */
    public array $servers = [];

    /** @var Path[][] */
    public array $paths = [];

    public ?Components $components = null;

    public ?Tags $tags = null;

    #[OverwritePropertyName('x-tagGroups')]
    public ?TagGroups $tagGroups = null;

    protected RouteCollection $routeCollection;

    /** @var bool[] */
    protected array $controllersToIgnore = [];

    /**
     * @param RouteCollection $routeCollection
     * @throws ReflectionException
     * @throws TypeDefinitionMissingOrWrong
     */
    public function __construct(RouteCollection &$routeCollection)
    {
        $this->routeCollection = $routeCollection;
        $this->components = new Components($this);
        self::$instance = $this;
        $this->buildDocumentation();
    }

    /**
     * Builds the documentation structure within document by analysing all Routes
     * @return void
     * @throws ReflectionException
     * @throws TypeDefinitionMissingOrWrong
     */
    public function buildDocumentation(): void
    {
        // extract general information from current route

        foreach ($this->routeCollection as $route) {
            /** @var Route $route */
            $ignoreController = false;
            $reflectionMethodOrClass = null;
            $controllerClass = self::getControllerClassForRoute($route);
            $controllerMethod = self::getControllerMethodForRoute($route);
            //we skip any class that is not a controller
            if (!is_a($controllerClass, AbstractController::class, true)) {
                continue;
            }
            if ($this->controllersToIgnore[$controllerClass] ?? false) {
                continue;
            }
            $controllerReflectionClass = ReflectionClass::instance($controllerClass);
            $controllerReflectionMethod = new ReflectionMethod($controllerClass, $controllerMethod);

            foreach ([$controllerReflectionClass, $controllerReflectionMethod] as $reflectionElementToProcess) {
                // more than one routes can have the same controller, in order to not process a controller class multiple times
                // we store the ones already processed and skip them
                if ($reflectionElementToProcess instanceof ReflectionClass) {
                    if (isset($this->controllersToIgnore[$reflectionElementToProcess->getName()])) {
                        continue;
                    } else {
                        $this->controllersToIgnore[$reflectionElementToProcess->getName()] = false;
                    }
                }
                foreach ($reflectionElementToProcess->getAttributes() as $attribute) {
                    $attributeInstance = $attribute->newInstance();
                    if ($attributeInstance instanceof OpenApi) {
                        $this->openapi = $attributeInstance->version;
                    }
                    if ($attributeInstance instanceof Info) {
                        $this->info = $attributeInstance;
                    }
                    if ($attributeInstance instanceof SecurityScheme) {
                        $this->securityScheme = $attributeInstance;
                    }
                    if ($attributeInstance instanceof Server) {
                        $this->servers[] = $attributeInstance;
                    }
                    if ($attributeInstance instanceof Ignore) {
                        $ignoreController = true;
                    }
                    if ($attributeInstance instanceof Tag) {
                        $this->addGlobalTag($attributeInstance);
                    }
                }
                if ($ignoreController) {
                    break;
                }
            }
            if ($ignoreController) {
                $this->controllersToIgnore[$reflectionElementToProcess->getName()] = true;
                continue;
            }
            $path = $route->getPath();
            if (!isset($this->paths[$path])) {
                $this->paths[$path] = [];
            }
            foreach ($route->getMethods() as $httpMethod){
                $this->paths[$path][strtolower($httpMethod)] = new Path($route, $httpMethod, $controllerReflectionClass, $controllerReflectionMethod);
            }
        }
        // sort tags if present by name
        $this?->tags?->sortByName();
    }

    /**
     * adds global tag, completes data if tag is already present, does not overwrite data
     * @param Tag $tag
     * @return void
     */
    public function addGlobalTag(Tag &$tag)
    {
        if (!$this->tags) {
            $this->tags = new Tags();
        }
        $this->tags->add($tag);
        if ($tag->group) {
            if (!$this->tagGroups) {
                $this->tagGroups = new TagGroups();
            }
            $tagGroup = $this->tagGroups->getByUniqueKey(TagGroup::uniqueKeyStatic($tag->group));
            if (!$tagGroup) {
                $tagGroup = new TagGroup();
                $tagGroup->name = $tag->group;
                $this->tagGroups->add($tagGroup);
            }
            $tagGroup->addTag($tag->name);
        }
        if (!$this->tagGroups) {
            $this->tagGroups = new TagGroups();
        }
        $this->tagGroups->sort(function (TagGroup $a, TagGroup $b) {
            if ($a->name == 'Models') {
                return 1;
            }
            if ($b->name == 'Models') {
                return -1;
            }
            return strcasecmp($a->name, $b->name);
        });
    }

    /**
     * returns instance singleton instance of the document
     * @return Document
     */
    public static function getInstance(): Document
    {
        return self::$instance;
    }

    public static function getControllerClassForRoute(Route $route): ?string
    {
        $default = $route->getDefaults();
        if (isset($default['_controller'])) {
            $controllerClass = substr($default['_controller'], 0, strpos($default['_controller'], '::'));
            return $controllerClass;
        }
        return null;
    }

    public static function getControllerMethodForRoute(Route $route): ?string
    {
        $default = $route->getDefaults();
        if (isset($default['_controller'])) {
            $controllerMethod = substr($default['_controller'], strpos($default['_controller'], '::') + 2);
            return $controllerMethod;
        }
        return null;
    }
}