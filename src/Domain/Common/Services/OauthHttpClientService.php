<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Infrastructure\Libs\Http\OAuth\CustomRefreshToken;
use DDD\Infrastructure\Libs\Http\OAuth\CustomToken;
use DDD\Infrastructure\Libs\Http\OAuth\CustomTokenPersistence;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use kamermans\OAuth2\GrantType\ClientCredentials;
use kamermans\OAuth2\GrantType\RefreshToken;
use kamermans\OAuth2\OAuth2Middleware;
use kamermans\OAuth2\Signer\ClientCredentials\BasicAuth;
use kamermans\OAuth2\Signer\ClientCredentials\PostFormData;

class OauthHttpClientService extends HttpClientService
{
    public const SIGNER_TYPE_POST_FORM_DATA = PostFormData::class;

    public const SIGNER_TYPE_BASIC_AUTH = BasicAuth::class;

    protected Client $client;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Configures the OAuth2 client with a token persistence implementation created internally.
     *
     * @param string $clientId OAuth2 client ID.
     * @param string $clientSecret OAuth2 client secret.
     * @param string $tokenRefreshUrl URL for token refresh.
     * @param string $accessToken Current access token.
     * @param string $refreshToken Current refresh token.
     * @param callable $onTokenRefresn Closure to handle new token data.
     * @param callable $onInvalidCredentials Closure executed if OAuth credentials are invalid. e.g. function (TokenInterface $token) use ($someConnectionObject) {var_dump($token);}
     */
    public function setOauthConfig(
        string|int $clientId,
        string $clientSecret,
        string $tokenRefreshUrl,
        string $accessToken,
        string $refreshToken,
        string $signerType = self::SIGNER_TYPE_POST_FORM_DATA,
        ?callable $onTokenRefresh = null,
        ?callable $onInvalidCredentials = null
    ): void {
        // OAuth2 configuration array using the relative token URL
        $config = [
            'client_id' => (string)$clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
        ];

        // Create a Guzzle client for token exchange with the correct base_uri
        $reauthClient = new Client(['base_uri' => $tokenRefreshUrl]);

        // This grant type is used to get a new Access Token and Refresh Token when
        //  no valid Access Token or Refresh Token is available
        $grantType = new ClientCredentials($reauthClient, $config);

        // Use our CustomRefreshToken grant which will trigger onInvalidCredentials if token refresh fails.
        if ($onInvalidCredentials !== null) {
            $refreshTokenGrantType = new CustomRefreshToken($reauthClient, $config, $onInvalidCredentials);
        } else {
            $refreshTokenGrantType = new RefreshToken($reauthClient, $config);
        }

        // Create the OAuth2 middleware
        $oauth = new OAuth2Middleware($grantType, $refreshTokenGrantType, new $signerType());
        // Set the current access token
        $oauth->setAccessToken(['access_token' => $accessToken]);

        // Create a new CustomTokenPersistence instance internally
        $tokenPersistence = new CustomTokenPersistence($onTokenRefresh);
        $token = new CustomToken($accessToken, $refreshToken);
        $tokenPersistence->setToken($token);
        // Attach our custom token persistence
        $oauth->setTokenPersistence($tokenPersistence);

        // Create a handler stack and push the OAuth middleware
        $stack = HandlerStack::create();
        $stack->push($oauth);

        // Initialize the Guzzle client with the OAuth2 middleware attached
        $this->client = new Client([
            'handler' => $stack,
            'auth' => 'oauth',
        ]);
    }

    public function getClient(): Client
    {
        return $this->client;
    }
}