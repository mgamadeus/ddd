<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Http;

use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * Thrown by {@see PublicUrlGuard::assertUrlIsPublic()} when a caller-supplied outbound URL is not a public http(s)
 * target (malformed, wrong scheme, or resolves to a private/internal address — an SSRF attempt). Extends
 * {@see BadRequestException} so a REST/JSON-RPC caller gets a clean 400-class rejection with the reason.
 */
class PublicUrlGuardException extends BadRequestException
{
}
