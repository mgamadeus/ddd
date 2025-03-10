<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs\Http\OAuth;

use Exception;
use GuzzleHttp\ClientInterface;
use kamermans\OAuth2\GrantType\RefreshToken;
use kamermans\OAuth2\Signer\ClientCredentials\SignerInterface;

/**
 * CustomRefreshToken extends the standard RefreshToken grant type.
 * It catches exceptions during token refresh and calls the onInvalidCredentials callback.
 */
class CustomRefreshToken extends RefreshToken
{
    // Public callback to be executed if token refresh fails.
    protected $onInvalidCredentials;

    public function __construct(ClientInterface $client, $config, ?callable $onInvalidCredentials = null)
    {
        parent::__construct($client, $config);
        if ($onInvalidCredentials) {
            $this->setOnInvalidCredentials($onInvalidCredentials);
        }
    }

    public function setOnInvalidCredentials(callable $onInvalidCredentials)
    {
        $this->onInvalidCredentials = $onInvalidCredentials;
    }

    /**
     * Overrides getRawData to catch errors during token refresh.
     * @param SignerInterface $clientCredentialsSigner
     * @param $refreshToken
     * @return array
     * @throws Exception
     */
    public function getRawData(SignerInterface $clientCredentialsSigner, $refreshToken = null)
    {
        try {
            return parent::getRawData($clientCredentialsSigner, $refreshToken);
        } catch (Exception $e) {
            // Trigger the callback when token refresh fails.
            call_user_func($this->onInvalidCredentials, $e);
            throw $e;
        }
    }
}