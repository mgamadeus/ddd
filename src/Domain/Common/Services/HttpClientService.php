<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use GuzzleHttp\Client;

class HttpClientService extends Service
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client(self::getRequestSettings());
    }

    protected static function getRequestSettings():array {
        $config = [
            'headers' => [
                'Connection' => Config::getEnv('HTTP_CLIENT_HEADERS_CONNECTION'),
                'Keep-Alive' => Config::getEnv('HTTP_CLIENT_HEADERS_KEEP_ALIVE'),
                'Accept-Charset' => Config::getEnv('HTTP_CLIENT_HEADERS_ACCEPT_CHARSET'),
                'Accept-Language' => Config::getEnv('HTTP_CLIENT_HEADERS_ACCEPT_LANGUAGE'),
                'Accept' => Config::getEnv('HTTP_CLIENT_HEADERS_ACCEPT'),
                'Content-Encoding' => Config::getEnv('HTTP_CLIENT_HEADERS_CONTENT_ENCODING'),
                'Accept-Encoding' => Config::getEnv('HTTP_CLIENT_HEADERS_ACCEPT_ENCODING')
            ],
            'http_errors' => Config::getEnv('HTTP_CLIENT_HTTP_ERRORS'),
            'timeout' => Config::getEnv('HTTP_CLIENT_HTTP_TIMEOUT')
        ];
        return $config;
    }

    public function getClient(): Client
    {
        return $this->client;
    }
}