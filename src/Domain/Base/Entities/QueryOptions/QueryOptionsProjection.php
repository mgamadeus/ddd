<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Infrastructure\Reflection\ReflectionClass;
use Throwable;

/**
 * Lets a class without a table of its own accept QueryOptions in the names of a class that has one. Typical use: an
 * entity was renamed (new class, new table, partly new property names) and old clients still send
 * `expand=oldEntities(filters=…;orderBy=…;select=…;top=…)`.
 *
 * - Validation: the filter, orderBy and select definitions of the class are derived from `targetClass` and exposed
 *   under the class' own names: a property of the same name maps 1:1, a renamed one through `propertyMap`
 *   (`['oldName' => 'newName']`). Only properties the class itself declares are exposed; column kind, temporal kind
 *   and fulltext support come from the target property.
 * - Execution: the loader of the class translates the received options with
 *   {@see AppliedQueryOptions::createProjectedQueryOptions()} and applies them to the target class' repository,
 *   under that repository's rights. Filter values are not changed.
 *
 * Declared on the entity, it also covers its EntitySet (and vice versa).
 */

#[Attribute(Attribute::TARGET_CLASS)]
class QueryOptionsProjection
{
    use BaseAttributeTrait;

    /**
     * @param string $targetClass The class whose definitions and repository are used (entity or entity set)
     * @param string[] $propertyMap Own property name => property name of the target class, for renamed properties
     */
    public function __construct(public string $targetClass, public array $propertyMap = [])
    {
    }

    /**
     * @param string $className An entity or an entity set
     * @return QueryOptionsProjection|null The projection declared on the class, else on its entity class (for a set)
     *                                     or on its entity set class (for an entity)
     */
    public static function getProjectionForClass(string $className): ?QueryOptionsProjection
    {
        if (!class_exists($className)) {
            return null;
        }
        $projection = ReflectionClass::instance($className)->getAttributeInstance(self::class);
        if ($projection instanceof QueryOptionsProjection) {
            return $projection;
        }
        try {
            $relatedClassName = is_a($className, EntitySet::class, true)
                ? $className::getEntityClass()
                : (method_exists($className, 'getEntitySetClass') ? $className::getEntitySetClass() : null);
        } catch (Throwable) {
            // a set whose entity class cannot be derived from its name (or the other way round) has no related
            // class to carry a projection
            return null;
        }
        if (!$relatedClassName || !class_exists($relatedClassName)) {
            return null;
        }
        $projection = ReflectionClass::instance($relatedClassName)->getAttributeInstance(self::class);
        return $projection instanceof QueryOptionsProjection ? $projection : null;
    }

    /**
     * @param string $ownPropertyName A property name of the projected class; a dotted path maps its first segment
     * @return string The name in the target class
     */
    public function getTargetPropertyName(string $ownPropertyName): string
    {
        $segments = explode('.', $ownPropertyName, 2);
        $segments[0] = $this->propertyMap[$segments[0]] ?? $segments[0];
        return implode('.', $segments);
    }

    /**
     * @param string $targetPropertyName A property name of the target class
     * @return string The name the projected class exposes it under
     */
    public function getOwnPropertyName(string $targetPropertyName): string
    {
        $ownPropertyName = array_search($targetPropertyName, $this->propertyMap, true);
        return is_string($ownPropertyName) ? $ownPropertyName : $targetPropertyName;
    }
}
