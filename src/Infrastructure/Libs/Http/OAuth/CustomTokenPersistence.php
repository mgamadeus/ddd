<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs\Http\OAuth;

use kamermans\OAuth2\Persistence\TokenPersistenceInterface;
use kamermans\OAuth2\Token\TokenInterface;

/**
 * CustomTokenPersistence
 *
 * Implements the TokenPersistenceInterface to store a TokenInterface instance.
 * A callback is executed whenever a token is saved.
 */
class CustomTokenPersistence implements TokenPersistenceInterface
{
    // Public callback to be executed when a new token is saved.
    protected mixed $onTokenRefresh;

    // Public property to store the token (this could be replaced with a database or cache store).
    protected CustomToken|TokenInterface|null $token;

    public function __construct(?callable $onTokenRefresh = null) {
        if ($onTokenRefresh) {
            $this->setOnTokenRefresh($onTokenRefresh);
        }
    }

    public function setOnTokenRefresh(callable $onTokenRefresh)
    {
        $this->onTokenRefresh = $onTokenRefresh;
    }

    /**
     * Restores the token data into the given token.
     *
     * @param TokenInterface $token The token object to restore data into.
     *
     * @return TokenInterface|null The stored token if available, otherwise null.
     */
    public function restoreToken(TokenInterface $token)
    {
        // If a token has been stored, return it.
        if ($this->hasToken()) {
            return $this->token;
        }
        return null;
    }

    /**
     * Sets the token without calling onTokenRefresh
     *
     * @param TokenInterface $token The token object to save.
     */
    public function setToken(TokenInterface $token)
    {
        $this->token = $token;
    }

    /**
     * Saves the token data.
     *
     * @param TokenInterface $token The token object to save.
     */
    public function saveToken(TokenInterface $token)
    {
        $token = new CustomToken($token->getAccessToken(), $token->getRefreshToken(), $token->getExpiresAt());
        $this->token = $token;
        // Execute the callback with the new token.
        if (isset($this->onTokenRefresh)) {
            call_user_func($this->onTokenRefresh, $token);
        }
    }

    /**
     * Expires the old token without deleting it
     */
    public function deleteToken()
    {
        if (isset($this->token)) {
            $this->token->setExpiresAt(1); // 1 second after 1970
        }
    }

    /**
     * Returns true if a token exists (even if it may not be valid).
     *
     * @return bool
     */
    public function hasToken()
    {
        return $this->token !== null;
    }
}