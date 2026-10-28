<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

use DDD\Domain\Common\Entities\Issues\IssueGroup;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Throwable;

/**
 * Sends the issues alerts to a Slack-compatible incoming webhook (`{"text": …}`), with a short timeout. A failed send
 * returns false — the registry job keeps the alert pending and retries on its next run.
 */
class IssuesAlertWebhookSender
{
    public const int TIMEOUT_SECONDS = 5;

    protected ?ClientInterface $httpClient = null;

    public function __construct(protected IssuesRegistrySettings $settings, ?ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient;
    }

    public function isConfigured(): bool
    {
        return $this->settings->alertWebhook !== null;
    }

    public function sendGroupAlert(IssueGroup $issueGroup): bool
    {
        return $this->send($this->buildGroupAlertText($issueGroup));
    }

    public function sendExplosionAlert(int $newGroupCount, ?string $environment = null): bool
    {
        return $this->send(sprintf(
            ':rotating_light: Issues%s: %d new root causes within an hour — fingerprint explosion, they are registered without one alert each.',
            $environment !== null ? ' [' . $environment . ']' : '',
            $newGroupCount
        ));
    }

    public function buildGroupAlertText(IssueGroup $issueGroup): string
    {
        $headline = $issueGroup->isRegression ? ':repeat: Regression' : ':new: New error';
        $lines = [
            sprintf('%s%s: *%s*', $headline, $issueGroup->environment !== null ? ' [' . $issueGroup->environment . ']' : '', $issueGroup->exceptionType ?? 'unknown'),
            '> ' . mb_substr((string)$issueGroup->sampleMessage, 0, 300),
            'slug: `' . ($issueGroup->slug ?? $issueGroup->groupHash) . '`',
            'entry points: ' . implode(', ', $issueGroup->entryPoints?->getNames() ?? []),
            sprintf(
                'release: %s · environment: %s · workspace: %s · events: %d',
                $issueGroup->releaseLastSeen ?? '?',
                $issueGroup->environment ?? '?',
                $issueGroup->workspace ?? '?',
                $issueGroup->eventCount
            ),
        ];
        $link = $this->settings->getAlertLink($issueGroup->groupHash, $issueGroup->environment);
        if ($link !== null) {
            $lines[] = $link;
        }
        return implode("\n", $lines);
    }

    public function send(string $text): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        try {
            $response = $this->getHttpClient()->request('POST', (string)$this->settings->alertWebhook, [
                'json' => ['text' => $text],
                'timeout' => self::TIMEOUT_SECONDS,
                'connect_timeout' => self::TIMEOUT_SECONDS,
            ]);
            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (Throwable) {
            return false;
        }
    }

    protected function getHttpClient(): ClientInterface
    {
        return $this->httpClient ??= new Client();
    }
}
