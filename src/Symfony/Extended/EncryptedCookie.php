<?php

declare (strict_types=1);

namespace DDD\Symfony\Extended;

use DateTimeInterface;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Libs\Encrypt;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

class EncryptedCookie extends Cookie
{
    public static function createEncryptedCookie(
        string $name,
        string $value,
        int|string|DateTimeInterface $expire = 0,
        ?string $path = '/',
        ?string $domain = null,
        ?bool $secure = null,
        bool $httpOnly = true,
        bool $raw = false,
        ?string $sameSite = self::SAMESITE_LAX
    ): Cookie {
        $password = Config::getEnv('ENCRYPTION_COOKIE_PASSWORD');
        $encryptedValue = 'ENC:' . Encrypt::encrypt($value, $password);
        return Cookie::create($name, $encryptedValue, $expire, $path, $domain, $secure, $httpOnly, $raw, $sameSite);
    }

    public static function getEncryptedCookie(Request $request, string $cookieName): ?string
    {
        $cookies = $request->cookies;
        if (!$cookies->has($cookieName)) {
            return null;
        }

        $password = Config::getEnv('ENCRYPTION_COOKIE_PASSWORD');
        $cookieValue = $cookies->get($cookieName);

        if (str_starts_with($cookieValue, 'ENC:')) {
            return Encrypt::decrypt(substr($cookieValue, 4), $password);
        } else {
            return $cookieValue;
        }
    }
}