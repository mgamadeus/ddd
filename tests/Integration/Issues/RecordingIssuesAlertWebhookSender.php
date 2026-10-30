<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Issues;

use DDD\Infrastructure\Logging\IssuesAlertWebhookSender;

/** Records the alert texts instead of posting them; {@see self::$failSends} simulates an unreachable webhook */
class RecordingIssuesAlertWebhookSender extends IssuesAlertWebhookSender
{
    /** @var string[] */
    public array $sentTexts = [];

    public bool $failSends = false;

    public function send(string $text): bool
    {
        if ($this->failSends) {
            return false;
        }
        $this->sentTexts[] = $text;
        return true;
    }
}
