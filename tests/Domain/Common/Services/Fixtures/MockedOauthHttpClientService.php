<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services\Fixtures;

use DDD\Domain\Common\Services\OauthHttpClientService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

/**
 * The OAuth client service with the token endpoint and the API replaced by queues of answers; every request to the
 * token endpoint is kept in {@see $tokenEndpointRequests}.
 */
class MockedOauthHttpClientService extends OauthHttpClientService
{
    public MockHandler $tokenEndpointMockHandler;

    public MockHandler $apiMockHandler;

    /** @var array[] Guzzle history entries of the token endpoint */
    public array $tokenEndpointRequests = [];

    public function __construct(array $tokenEndpointAnswers, array $apiAnswers)
    {
        parent::__construct();
        $this->tokenEndpointMockHandler = new MockHandler($tokenEndpointAnswers);
        $this->apiMockHandler = new MockHandler($apiAnswers);
    }

    protected function createTokenEndpointClient(string $tokenRefreshUrl): Client
    {
        $handlerStack = HandlerStack::create($this->tokenEndpointMockHandler);
        $handlerStack->push(Middleware::history($this->tokenEndpointRequests));
        return new Client(['base_uri' => $tokenRefreshUrl, 'handler' => $handlerStack]);
    }

    protected function createApiHandlerStack(): HandlerStack
    {
        return HandlerStack::create($this->apiMockHandler);
    }
}
