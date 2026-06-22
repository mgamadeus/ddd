<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\QueryOptions;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;
use DDD\Domain\Base\Entities\QueryOptions\AppliedQueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\ExpandOptions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Entities\QueryOptions\SelectOptions;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Reflection\ReflectionClass;
use ReflectionException;

#[Attribute]
class DtoQueryOptions extends ValueObject
{
    use BaseAttributeTrait;

    /** The four QueryOptions families a DTO can expose. {@see self::$expose}. */
    public const string FILTERS = 'filters';
    public const string ORDER_BY = 'orderBy';
    public const string EXPAND = 'expand';
    public const string SELECT = 'select';

    public string $baseEntity;

    /**
     * Which QueryOptions families to RENDER in the generated documentation (OpenApi + MCP). **null = expose ALL**
     * (default, backward-compatible); a list (e.g. `[DtoQueryOptions::FILTERS]`) renders ONLY those — the others are
     * omitted from the schema entirely. This is a **documentation-only** narrowing: the trait properties stay present,
     * hydrated and validated, so server code can still set them (e.g. a controller forcing `expand=replies`). Use it on
     * a read whose endpoint genuinely uses only SOME options — **see the viability rule below.**
     *
     * ⚠️ VIABILITY (by design): only narrow a family you have VERIFIED the repo does NOT actually apply. This is
     * realistic for **Argus-backed** entities — an Argus repo proxies an external API and does NOT translate
     * `expand`/`orderBy`/`select` into the call, so they are doc-only there. A **DB repo** DOES apply
     * select/expand/orderBy at the query layer (Doctrine), so the agent could use them at ANY time — you cannot prove
     * they are unused; do NOT narrow a DB-backed read. Check the entity's `LazyLoadRepo` + how the controller/tool loads
     * before applying `expose`.
     *
     * @var string[]|null
     */
    public ?array $expose;

    public function __construct(string $baseEntity, ?array $expose = null)
    {
        if (!class_exists($baseEntity)) {
            throw new InternalErrorException(
                "Defined Entity class $baseEntity to extract QueryOptions from does not exist."
            );
        }
        $this->baseEntity = $baseEntity;
        $this->expose = $expose;
        parent::__construct();
    }

    /** True if a QueryOptions family ({@see self::FILTERS} …) should be rendered in the docs. null $expose = all. */
    public function exposes(string $family): bool
    {
        return $this->expose === null || in_array($family, $this->expose, true);
    }

    /** Maps a DTO property's QueryOptions Options TYPE to its family name, or null if the type is not a QueryOptions type. */
    public static function queryOptionFamilyForTypeName(string $typeName): ?string
    {
        return match ($typeName) {
            FiltersOptions::class => self::FILTERS,
            OrderByOptions::class => self::ORDER_BY,
            ExpandOptions::class => self::EXPAND,
            SelectOptions::class => self::SELECT,
            default => null,
        };
    }

    /**
     * Returns QueryOptions Attribute Instance from base Entity
     * @return QueryOptions|null
     * @throws ReflectionException
     */
    public function getQueryOptions(): ?AppliedQueryOptions
    {
        /** @var QueryOptionsTrait $baseEntityClass */
        $baseEntityClass = $this->baseEntity;
        /** @var QueryOptions|null $queryOptionsAttributeInstance */
        $baseReflectionClass = ReflectionClass::instance($this->baseEntity);
        if ($baseReflectionClass->hasTrait(QueryOptionsTrait::class)) {
            return $baseEntityClass::getDefaultQueryOptions();
        }
        return null;
    }
}