<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Sentry;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Throwable;

/**
 * Posts one event as a Sentry envelope. The outcome tells the forwarder whether to move on: SENT, REJECTED (Sentry
 * refused this event for good: 4xx other than 429 — dropped, never retried) or RETRY (rate limit, server error,
 * network: stop and send it again on the next run).
 */
class SentryEnvelopeSender
{
    public const string OUTCOME_SENT = 'SENT';
    public const string OUTCOME_REJECTED = 'REJECTED';
    public const string OUTCOME_RETRY = 'RETRY';

    public const int TIMEOUT_SECONDS = 10;

    protected ?ClientInterface $httpClient = null;

    public function __construct(protected SentryDsn $sentryDsn, ?ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient;
    }

    /** The envelope: header line, item header line, event payload */
    public function buildEnvelope(string $eventId, string $eventJson): string
    {
        return json_encode(['event_id' => $eventId, 'sent_at' => gmdate('Y-m-d\TH:i:s\Z')]) . "\n"
            . json_encode(['type' => 'event', 'content_type' => 'application/json', 'length' => strlen($eventJson)]) . "\n"
            . $eventJson . "\n";
    }

    /** @return string One of the OUTCOME_* constants */
    public function sendEvent(string $eventId, string $eventJson): string
    {
        try {
            $response = $this->getHttpClient()->request('POST', $this->sentryDsn->getEnvelopeUrl(), [
                'headers' => [
                    'Content-Type' => 'application/x-sentry-envelope',
                    'X-Sentry-Auth' => $this->sentryDsn->getAuthHeader(),
                ],
                'body' => $this->buildEnvelope($eventId, $eventJson),
                'timeout' => self::TIMEOUT_SECONDS,
                'connect_timeout' => self::TIMEOUT_SECONDS,
                'http_errors' => false,
            ]);
        } catch (Throwable) {
            return self::OUTCOME_RETRY;
        }
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 200 && $statusCode < 300) {
            return self::OUTCOME_SENT;
        }
        return $statusCode === 429 || $statusCode >= 500 ? self::OUTCOME_RETRY : self::OUTCOME_REJECTED;
    }

    protected function getHttpClient(): ClientInterface
    {
        return $this->httpClient ??= new Client();
    }
}
