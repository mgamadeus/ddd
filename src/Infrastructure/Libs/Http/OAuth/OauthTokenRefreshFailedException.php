<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs\Http\OAuth;

/**
 * The refresh request failed in a way that may pass: a 5xx, 408 or 429 answer of the token endpoint, or no answer at
 * all (connection, timeout). The refresh token was not judged; retry later and keep the authorization.
 */
class OauthTokenRefreshFailedException extends OauthTokenRefreshException
{
}
