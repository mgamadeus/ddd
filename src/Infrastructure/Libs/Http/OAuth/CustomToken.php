<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs\Http\OAuth;

use InvalidArgumentException;
use kamermans\OAuth2\Token\RawToken;

/**
 * CustomRefreshToken extends the standard RefreshToken grant type.
 * It catches exceptions during token refresh and calls the onInvalidCredentials callback.
 */
class CustomToken extends RawToken
{
    /**
     * Access Token.
     *
     * @var string
     */
    protected ?string $accessToken;

    /**
     * Refresh Token.
     *
     * @var string
     */
    protected ?string $refreshToken;

    /**
     * Expiration timestamp.
     *
     * @var int
     */
    protected ?int $expiresAt;

    /**
     * @param string $accessToken
     * @param string $refreshToken
     * @param int $expiresAt
     */
    public function __construct(string $accessToken = null, string $refreshToken = null, int $expiresAt = null)
    {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->expiresAt = $expiresAt;
    }

    /**
     * @return string The access token
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * @return string|null The refresh token
     */
    public function getRefreshToken()
    {
        return $this->refreshToken;
    }

    /**
     * @return int The expiration timestamp
     */
    public function getExpiresAt()
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(int $expiresAt)
    {
        $this->expiresAt = $expiresAt;
    }

    public function setAccessToken(string $accessToken)
    {
        $this->accessToken = $accessToken;
    }

    public function setRefreshToken(string $refreshToken)
    {
        $this->refreshToken = $refreshToken;
    }

    /**
     * @return bool
     */
    public function isExpired()
    {
        return $this->expiresAt && $this->expiresAt < time();
    }

    /**
     * Serialize Token data
     * @return string Token data
     */
    public function serialize()
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
        ];
    }

    /**
     * Unserialize token data
     * @return self
     */
    public function unserialize(array $data)
    {
        if (!isset($data['access_token'])) {
            throw new InvalidArgumentException('Unable to create a RawToken without an "access_token"');
        }

        $this->accessToken = $data['access_token'];
        $this->refreshToken = isset($data['refresh_token']) ? $data['refresh_token'] : null;
        $this->expiresAt = isset($data['expires_at']) ? $data['expires_at'] : null;

        return $this;
    }
}