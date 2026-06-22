<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Reflection\ReflectionDocComment;
use DDD\Infrastructure\Reflection\ReflectionProperty;
use DDD\Presentation\Base\OpenApi\Attributes\SharedRequestParameter;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptionsTrait;

/**
 * Single source of truth for the shared request parameters (`outputFormat`, `noCache`, and the paging `skip`/`top`/
 * `skiptoken`) — those marked {@see SharedRequestParameter}. Their full documentation is emitted ONCE per
 * surface (OpenApi `info.description`, the MCP/agent system context, the MCP `initialize` instructions) instead of
 * repeated on every endpoint/tool parameter. Each parameter's OWN DocComment stays the text source — this class only
 * collects them. Built once and cached for the process (deterministic — the marked set is framework-fixed).
 */
class SharedRequestParametersSyntax
{
    public const string HEADING = 'Shared request parameters';

    /** The inline per-parameter pointer both documenters use in place of repeating the full description. */
    public const string PARAMETER_POINTER = 'Common request parameter — see "Shared request parameters".';

    /** @var string[] The framework classes that declare {@see SharedRequestParameter} properties. */
    protected const array SOURCE_CLASSES = [RequestDto::class, DtoQueryOptionsTrait::class];

    protected static ?string $cachedDocumentation = null;

    /**
     * The "## Shared request parameters" block: one line per marked parameter, its DocComment as the description.
     */
    public static function getDocumentation(): string
    {
        if (self::$cachedDocumentation !== null) {
            return self::$cachedDocumentation;
        }
        $lines = [];
        foreach (self::SOURCE_CLASSES as $sourceClass) {
            $reflectionClass = ReflectionClass::instance($sourceClass);
            foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC) as $reflectionProperty) {
                if (!$reflectionProperty->getAttributes(SharedRequestParameter::class)) {
                    continue;
                }
                $description = '';
                if ($docCommentText = $reflectionProperty->getDocComment()) {
                    $description = (new ReflectionDocComment($docCommentText))->getDescription(true, false);
                    $description = trim((string)preg_replace('/\s+/', ' ', $description));
                }
                $lines[] = "- `{$reflectionProperty->getName()}`" . ($description !== '' ? ': ' . $description : '');
            }
        }
        self::$cachedDocumentation = '## ' . self::HEADING . "\n\n"
            . "These common parameters behave identically wherever they appear:\n\n"
            . implode("\n", $lines);
        return self::$cachedDocumentation;
    }
}
