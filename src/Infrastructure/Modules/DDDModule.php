<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Modules;

/**
 * Base class for DDD modules. Each module is a separate Composer package
 * that self-registers its services, config directories, and optional controller routes.
 *
 * Modules are discovered automatically via the "ddd-module" key in their
 * composer.json "extra" section.
 */
abstract class DDDModule
{
    /**
     * Absolute path to this module's src/ directory.
     * Used by the compiler pass to register services.
     */
    abstract public static function getSourcePath(): string;

    /**
     * Absolute path to this module's config/app/ directory, or null if none.
     * Registered via Config::addConfigDirectory() at boot time.
     *
     * Config priority: App > Module > DDD Core (last registered = first searched).
     */
    public static function getConfigPath(): ?string
    {
        return null;
    }

    /**
     * Namespace prefixes whose services should be public in the container.
     * e.g. ['DDD\Domain\Common\Services\PoliticalEntities\']
     */
    public static function getPublicServiceNamespaces(): array
    {
        return [];
    }

    /**
     * Namespace substrings to exclude from auto-wiring.
     * Matches against the fully qualified class name.
     */
    public static function getExcludePatterns(): array
    {
        return ['Attributes', 'Exceptions', 'Traits', 'Validators', 'Interfaces', 'LazyLoad', 'QueryOptions'];
    }

    /**
     * Controller directories available for routing.
     * Keys are route prefixes, values are absolute directory paths.
     * e.g. ['/api/batch' => __DIR__ . '/Presentation/Api/Batch']
     *
     * These are NOT auto-registered. The consuming app decides which to import
     * in its routes.yaml. This method provides a discoverable registry of
     * available controller paths that modules ship.
     */
    public static function getControllerPaths(): array
    {
        return [];
    }
}
