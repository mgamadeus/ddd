<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Router\Routes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use Symfony\Component\Routing\Attribute\DeprecatedAlias;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Route extends \Symfony\Component\Routing\Attribute\Route
{
    use SerializerTrait, BaseAttributeTrait;

    public function __construct(
        public string|array|null $path = null,
        public ?string $name = null,
        public array $requirements = [],
        public array $options = [],
        public array $defaults = [],
        public ?string $host = null,
        array|string $methods = [],
        array|string $schemes = [],
        public ?string $condition = null,
        public ?int $priority = null,
        ?string $locale = null,
        ?string $format = null,
        ?bool $utf8 = null,
        ?bool $stateless = null,
        string|array|null $env = null,
        string|DeprecatedAlias|array $alias = [],
    ) {
        $this->addRouteParamRequirements($path, $requirements);

        parent::__construct(
            $path,
            $name,
            $requirements,
            $options,
            $defaults,
            $host,
            $methods,
            $schemes,
            $condition,
            $priority,
            $locale,
            $format,
            $utf8,
            $stateless,
            $env,
            $alias
        );
    }

    /**
     * @param array|string|null $path
     * @param array $requirements
     * @return void
     */
    protected function addRouteParamRequirements(array|string|null $path, array &$requirements): void
    {
        if (!$path || !is_string($path)) {
            return;
        }
        // Find all parameters ending with Id, to enforce integer Id's
        preg_match_all('/\{([^{}]+Id(?:<[^>]+>)?)\}/', $path, $matches);

        foreach ($matches[1] as $paramName) {
            // Look for <...> requirement within the match
            if (preg_match('/(<[^>]+>)/', $paramName, $requirementMatch)) {
                $paramRequirement = $requirementMatch[1];
            } else {
                $paramRequirement = null;
            }

            // If a requirement is defined in the route, skip setting a default
            if ($paramRequirement) {
                continue;
            }

            // If $requirements has a directive for the current parameter, use it
            if (isset($requirements[$paramName])) {
                continue;
            }

            // Set the default if no requirement is defined and $requirements doesn't contain the parameter
            $requirements[$paramName] = '\d*';
        }
    }
}
