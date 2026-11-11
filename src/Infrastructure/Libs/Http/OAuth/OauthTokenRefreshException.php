<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs\Http\OAuth;

use RuntimeException;
use Throwable;

/**
 * A refresh of an OAuth access token with the refresh token did not succeed. The previous exception is the failure of
 * the refresh request itself (a Guzzle `BadResponseException` with the token endpoint's answer, or a transfer error).
 * The message names the HTTP status only, never a token.
 */
class OauthTokenRefreshException extends RuntimeException
{
    /** @var int|null The HTTP status of the token endpoint's answer; null when no answer arrived */
    protected ?int $httpStatusCode;

    public function __construct(string $message, ?int $httpStatusCode, ?Throwable $previous = null)
    {
        parent::__construct($message, $httpStatusCode ?? 0, $previous);
        $this->httpStatusCode = $httpStatusCode;
    }

    /** @return int|null The HTTP status of the token endpoint's answer; null when no answer arrived */
    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }
}
