<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Attributes;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

/**
 * Marks a request-DTO parameter as a SHARED framework parameter (`outputFormat`, `noCache`, and the paging
 * `skip`/`top`/`skiptoken`) whose meaning is identical wherever it appears. The documenters (OpenApi
 * {@see \DDD\Presentation\Base\OpenApi\Pathes\PathParameter} and the RC MCP documenter) render only a short pointer for
 * the parameter inline and emit the full documentation ONCE per surface — see
 * {@see \DDD\Presentation\Base\Dtos\SharedRequestParametersSyntax} — instead of repeating the identical description on
 * every tool. The property's own DocComment stays the source of that once-emitted text.
 *
 * Pure marker (no arguments). Distinct from QueryOptions (filters/orderBy/expand/select), which carry per-endpoint
 * allowed-property lists and are handled separately.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class SharedRequestParameter
{
    use BaseAttributeTrait;
}
