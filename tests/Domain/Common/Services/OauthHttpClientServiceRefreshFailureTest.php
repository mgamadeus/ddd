<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Services;

use DDD\Infrastructure\Libs\Http\OAuth\OauthTokenRefreshException;
use DDD\Infrastructure\Libs\Http\OAuth\OauthTokenRefreshFailedException;
use DDD\Infrastructure\Libs\Http\OAuth\OauthTokenRefreshRefusedException;
use DDD\Tests\Domain\Common\Services\Fixtures\MockedOauthHttpClientService;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use kamermans\OAuth2\Exception\AccessTokenRequestException;
use PHPUnit\Framework\TestCase;

/**
 * A request whose access token is refused (401) refreshes it with the refresh token. Without the client-credentials
 * fallback the refresh failure reaches the caller as refused (the refresh token is gone) or failed (it may pass).
 */
class OauthHttpClientServiceRefreshFailureTest extends TestCase
{
    protected int $invalidCredentialsCalls = 0;

    /** @var array[] Tokens handed to the refresh callback */
    protected array $refreshedTokens = [];

    protected function createService(array $tokenEndpointAnswers, array $apiAnswers, bool $fallBack): MockedOauthHttpClientService
    {
        $oauthHttpClientService = new MockedOauthHttpClientService($tokenEndpointAnswers, $apiAnswers);
        $oauthHttpClientService->setOauthConfig(
            'client-id',
            'client-secret',
            'https://provider.example.test/oauth/token',
            'expired-access-token',
            'stored-refresh-token',
            onTokenRefresh: function (mixed $token): void {
                $this->refreshedTokens[] = $token;
            },
            onInvalidCredentials: function (): void {
                $this->invalidCredentialsCalls++;
            },
            fallBackToClientCredentialsWhenRefreshFails: $fallBack
        );
        return $oauthHttpClientService;
    }

    protected function requestRefreshFailure(Response|ConnectException $tokenEndpointAnswer): OauthTokenRefreshException
    {
        $oauthHttpClientService = $this->createService([$tokenEndpointAnswer, new Response(200, [], '{"access_token":"cc"}')], [new Response(401)], false);
        try {
            $oauthHttpClientService->getClient()->get('https://api.example.test/v1/activities');
        } catch (OauthTokenRefreshException $oauthTokenRefreshException) {
            self::assertCount(1, $oauthHttpClientService->tokenEndpointRequests, 'no client-credentials request after the refresh');
            self::assertStringContainsString(
                'grant_type=refresh_token',
                (string)$oauthHttpClientService->tokenEndpointRequests[0]['request']->getBody()
            );
            self::assertStringNotContainsString('stored-refresh-token', $oauthTokenRefreshException->getMessage());
            return $oauthTokenRefreshException;
        }
        self::fail('the refresh failure must reach the caller');
    }

    public function testARefusedRefreshTokenReachesTheCallerAsRefused(): void
    {
        $exception = $this->requestRefreshFailure(new Response(400, [], '{"error":"invalid_grant"}'));

        self::assertInstanceOf(OauthTokenRefreshRefusedException::class, $exception);
        self::assertSame(400, $exception->getHttpStatusCode());
        self::assertInstanceOf(BadResponseException::class, $exception->getPrevious());
        self::assertSame(1, $this->invalidCredentialsCalls);
    }

    public function testAServerErrorOfTheTokenEndpointReachesTheCallerAsFailed(): void
    {
        $exception = $this->requestRefreshFailure(new Response(503));

        self::assertInstanceOf(OauthTokenRefreshFailedException::class, $exception);
        self::assertSame(503, $exception->getHttpStatusCode());
        self::assertSame(0, $this->invalidCredentialsCalls);
    }

    public function testRateLimitAndTimeoutAnswersAreFailuresThatMayPass(): void
    {
        foreach ([429, 408] as $httpStatusCode) {
            $exception = $this->requestRefreshFailure(new Response($httpStatusCode));
            self::assertInstanceOf(OauthTokenRefreshFailedException::class, $exception);
            self::assertSame($httpStatusCode, $exception->getHttpStatusCode());
        }
        self::assertSame(0, $this->invalidCredentialsCalls);
    }

    public function testNoAnswerIsAFailureThatMayPass(): void
    {
        $exception = $this->requestRefreshFailure(
            new ConnectException('connection refused', new Request('POST', 'https://provider.example.test/oauth/token'))
        );

        self::assertInstanceOf(OauthTokenRefreshFailedException::class, $exception);
        self::assertNull($exception->getHttpStatusCode());
        self::assertSame(0, $this->invalidCredentialsCalls);
    }

    public function testASuccessfulRefreshRepeatsTheRequestWithTheNewToken(): void
    {
        $oauthHttpClientService = $this->createService(
            [new Response(200, [], '{"access_token":"new-access","refresh_token":"new-refresh","expires_in":3600}')],
            [new Response(401), new Response(200, [], '{"ok":true}')],
            false
        );

        $response = $oauthHttpClientService->getClient()->get('https://api.example.test/v1/activities');

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->refreshedTokens);
        self::assertSame(0, $this->invalidCredentialsCalls);
    }

    public function testTheDefaultFallsBackToClientCredentialsAsBefore(): void
    {
        $oauthHttpClientService = $this->createService([new Response(503), new Response(401)], [new Response(401)], true);

        try {
            $oauthHttpClientService->getClient()->get('https://api.example.test/v1/activities');
            self::fail('the client-credentials fallback fails too');
        } catch (AccessTokenRequestException $accessTokenRequestException) {
            self::assertCount(2, $oauthHttpClientService->tokenEndpointRequests);
            self::assertStringContainsString(
                'grant_type=client_credentials',
                (string)$oauthHttpClientService->tokenEndpointRequests[1]['request']->getBody()
            );
        }
    }
}
