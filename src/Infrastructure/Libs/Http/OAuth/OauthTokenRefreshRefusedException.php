<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs\Http\OAuth;

/**
 * The token endpoint refused the refresh token: a 4xx answer other than 408 and 429 (typically 400 `invalid_grant`
 * or 401). Retrying with the same refresh token will not help; the authorization has to be granted again.
 */
class OauthTokenRefreshRefusedException extends OauthTokenRefreshException
{
}
