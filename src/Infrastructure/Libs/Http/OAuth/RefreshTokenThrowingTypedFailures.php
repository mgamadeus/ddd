<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs\Http\OAuth;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;
use kamermans\OAuth2\GrantType\RefreshToken;
use kamermans\OAuth2\Signer\ClientCredentials\SignerInterface;

/**
 * A refresh-token grant whose failures reach the caller as they happened: {@see OauthTokenRefreshRefusedException}
 * when the token endpoint refused the refresh token, {@see OauthTokenRefreshFailedException} when the request failed
 * in a way that may pass. Neither is a Guzzle `BadResponseException`, so the OAuth2 handler does not drop the token
 * and fall back to its main grant (client credentials): the refresh failure is the caller's answer.
 *
 * `$onInvalidCredentials` runs only for a refused refresh token, never for a failure that may pass.
 */
class RefreshTokenThrowingTypedFailures extends RefreshToken
{
    /** @var callable|null Called with the refused exception before it is thrown */
    protected $onInvalidCredentials;

    public function __construct(ClientInterface $client, $config, ?callable $onInvalidCredentials = null)
    {
        parent::__construct($client, $config);
        $this->onInvalidCredentials = $onInvalidCredentials;
    }

    /**
     * @param SignerInterface $clientCredentialsSigner
     * @param string|null $refreshToken
     * @return array
     * @throws OauthTokenRefreshRefusedException When the token endpoint refused the refresh token
     * @throws OauthTokenRefreshFailedException When the refresh request failed in a way that may pass
     */
    public function getRawData(SignerInterface $clientCredentialsSigner, $refreshToken = null)
    {
        try {
            return parent::getRawData($clientCredentialsSigner, $refreshToken);
        } catch (BadResponseException $badResponseException) {
            $httpStatusCode = $badResponseException->getResponse()->getStatusCode();
            if (static::isRefusingStatusCode($httpStatusCode)) {
                $refusedException = new OauthTokenRefreshRefusedException(
                    "OAuth token refresh refused with HTTP $httpStatusCode",
                    $httpStatusCode,
                    $badResponseException
                );
                if ($this->onInvalidCredentials !== null) {
                    call_user_func($this->onInvalidCredentials, $refusedException);
                }
                throw $refusedException;
            }
            throw new OauthTokenRefreshFailedException(
                "OAuth token refresh failed with HTTP $httpStatusCode",
                $httpStatusCode,
                $badResponseException
            );
        } catch (TransferException $transferException) {
            throw new OauthTokenRefreshFailedException('OAuth token refresh failed without an answer', null, $transferException);
        }
    }

    /** Whether an answer of the token endpoint refuses the refresh token: 4xx except 408 and 429 */
    public static function isRefusingStatusCode(int $httpStatusCode): bool
    {
        return $httpStatusCode >= 400 && $httpStatusCode < 500 && $httpStatusCode !== 408 && $httpStatusCode !== 429;
    }
}
