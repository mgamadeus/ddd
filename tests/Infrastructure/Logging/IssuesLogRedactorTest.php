<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Logging;

use DDD\Infrastructure\Logging\IssuesLogEvent;
use DDD\Infrastructure\Logging\IssuesLogRedactor;
use PHPUnit\Framework\TestCase;

class IssuesLogRedactorTest extends TestCase
{
    public function testSecretKeysAreRedactedAlsoNested(): void
    {
        $redactedContext = (new IssuesLogRedactor())->redactContext([
            'password' => 'hunter2',
            'route_name' => 'api_login',
            'message' => ['payload' => ['apiKey' => 'k-123', 'name' => 'Anna', 'Authorization' => 'Bearer x']],
        ]);

        $this->assertSame(IssuesLogRedactor::REDACTED, $redactedContext['password']);
        $this->assertSame('api_login', $redactedContext['route_name']);
        $this->assertSame(IssuesLogRedactor::REDACTED, $redactedContext['message']['payload']['apiKey']);
        $this->assertSame(IssuesLogRedactor::REDACTED, $redactedContext['message']['payload']['Authorization']);
        $this->assertSame('Anna', $redactedContext['message']['payload']['name']);
    }

    public function testFreeTextLosesEmailsCredentialsAndLongNumbers(): void
    {
        $this->assertSame(
            'login of [EMAIL] with Bearer [REDACTED] failed, card [NUMBER]',
            (new IssuesLogRedactor())->sanitizeText('login of anna.berg@example.com with Bearer eyJhbGciOi.abc failed, card 4111111111111111')
        );
    }

    public function testTheCapCutsTheTraceBeforeTheMessage(): void
    {
        $event = new IssuesLogEvent('Exception occurred: short message', [
            'error.stack_trace' => str_repeat('#0 frame ', 2000),
            'route_name' => 'api_orders_list',
        ]);

        (new IssuesLogRedactor())->capEvent($event, 2048);

        $this->assertSame('Exception occurred: short message', $event->message);
        $this->assertStringEndsWith('…[cut]', $event->context['error.stack_trace']);
        $this->assertSame('api_orders_list', $event->context['route_name']);
        $this->assertLessThanOrEqual(2048, strlen($event->message) + strlen(json_encode($event->context, JSON_UNESCAPED_SLASHES)));
    }

    public function testTheMessageIsCutLastWhenTheContextAloneDoesNotFit(): void
    {
        $event = new IssuesLogEvent(str_repeat('m', 5000), ['route_name' => 'api_orders_list']);

        (new IssuesLogRedactor())->capEvent($event, 1024);

        $this->assertStringEndsWith('…[cut]', $event->message);
        $this->assertLessThan(1100, strlen($event->message));
    }
}
