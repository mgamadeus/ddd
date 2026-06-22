<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Entities\QueryOptions;

/**
 * Single source of truth for the generic, endpoint-INDEPENDENT QueryOptions grammar (filters / orderBy / expand /
 * select). The grammar used to live as PHPDoc on the {@see \DDD\Presentation\Base\QueryOptions\DtoQueryOptionsTrait}
 * properties, which both documenters (OpenApi {@see \DDD\Presentation\Base\OpenApi\Pathes\PathParameterSchema} and the
 * RC MCP documenter) pulled and emitted into EVERY parameter description — byte-identical on every endpoint/tool.
 *
 * This class aggregates each Options type's own {@see FiltersOptions::getSyntaxDocumentation()} etc. so the grammar can
 * be emitted ONCE per surface (OpenApi `info.description`; the MCP/agent system instructions; the external MCP server
 * `instructions`). Per-parameter, the documenters now emit only the short {@see FiltersOptions::getParameterSummary()}
 * plus the endpoint-specific allowed-property list (which carries the per-endpoint custom options unchanged).
 */
class QueryOptionsSyntax
{
    public const string HEADING = 'QueryOptions syntax';

    /**
     * The full grammar block (all four option families) under one "## QueryOptions syntax" heading, with a short
     * preface. Pure / static — safe to call from container-less contexts (e.g. the agent's AgentContext VO).
     */
    public static function getSyntaxDocumentation(): string
    {
        $intro = '## ' . self::HEADING . "\n\n"
            . "These OData-inspired query parameters (`filters`, `orderBy`, `expand`, `select`) share the grammar below "
            . "on every endpoint/tool. Each parameter's description lists only its endpoint-specific allowed properties; "
            . "the syntax for all of them is documented here once.\n\n";

        return $intro
            . FiltersOptions::getSyntaxDocumentation() . "\n\n"
            . OrderByOptions::getSyntaxDocumentation() . "\n\n"
            . ExpandOptions::getSyntaxDocumentation() . "\n\n"
            . SelectOptions::getSyntaxDocumentation();
    }
}
