<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Libs;

use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Cache\Marshaller;
use DDD\Infrastructure\Cache\Redis;

class AuthRedis
{
    /** @var array */
    private static array $cacheConfig;

    /** @var array */
    private static array $sessionConfig;

    /** @var Cache */
    private static Cache $cacheInstance;

    private static string $marshallerClass = Marshaller::class;

    /**
     * @return void
     */
    private static function populateConfig(): void
    {
        if (!isset(self::$cacheConfig)) {
            $redisCacheConfig = Cache::getCacheConfig(Cache::CACHE_GROUP_REDIS);
            self::$cacheConfig = [
                'host' => $redisCacheConfig['host'],
                'port' => $redisCacheConfig['port'],
                'password' => $redisCacheConfig['password'],
                'database' => $redisCacheConfig['database'],
                'defaultTTL' => $redisCacheConfig['defaultTTL'],
                'namespace' => Config::getEnv('AUTH_SESSION_NAMESPACE')
            ];
        }

        if (!isset(self::$sessionConfig)) {
            self::$sessionConfig = [
                'cookieName' => Config::getEnv('AUTH_SESSION_COOKIE_NAME'),
                'accountIdKey' => Config::getEnv('AUTH_SESSION_ACCOUNT_ID_KEY')
            ];
        }
    }

    /**
     * @return string|null
     */
    public static function getSessionCookie(): ?string
    {
        self::populateConfig();
        return $_COOKIE[self::$sessionConfig['cookieName']] ?? null;
    }


    /**
     * @return Cache
     */
    private static function getCacheInstance(): Cache
    {
        self::populateConfig();
        if (isset(self::$cacheInstance)) {
            return self::$cacheInstance;
        }

        $marshaller = class_exists(self::$marshallerClass) ? new self::$marshallerClass() : null;
        self::$cacheInstance = new Redis(self::$cacheConfig, $marshaller);
        return self::$cacheInstance;
    }

    /**
     * @return mixed|null
     */
    public static function getAuthAccountId(): mixed
    {
        $sessionCookie = self::getSessionCookie();
        if (!$sessionCookie) {
            return null;
        }
        $authData = self::getCacheInstance()->get($sessionCookie);
        if (!$authData || !is_array($authData)) {
            return null;
        }
        return $authData[self::$sessionConfig['accountIdKey']] ?? null;
    }
}
