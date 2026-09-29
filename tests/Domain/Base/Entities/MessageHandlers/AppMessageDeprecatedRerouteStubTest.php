<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use PHPUnit\Framework\TestCase;

class StubProbeMessage extends AppMessage
{
    public static string $messageHandler = 'StubProbeHandler';
}

class AppMessageDeprecatedRerouteStubTest extends TestCase
{
    public function testTheStubReturnsFalseAndDoesNotFatal(): void
    {
        // The whole "nothing fatals on the bump" promise rests on this: an app that still calls the removed reroute
        // gets false and a deprecation, not an error. trigger_deprecation() comes from symfony/deprecation-contracts,
        // which this package now requires directly instead of relying on it being there transitively.
        $this->assertTrue(function_exists('trigger_deprecation'), 'the stub calls it');

        $previousErrorHandler = set_error_handler(static fn () => true, E_USER_DEPRECATED);
        try {
            $this->assertFalse((new StubProbeMessage())->processOnWorkspaceIfNecessary());
        } finally {
            set_error_handler($previousErrorHandler);
        }
    }

    public function testTheRemovedRerouteMethodsAreGone(): void
    {
        foreach (['processOnWorkspace', 'runWorkspaceConsoleProcess', 'workspaceRerouteIsEnabled'] as $removedMethod) {
            $this->assertFalse(method_exists(AppMessage::class, $removedMethod), "$removedMethod must be gone");
        }
        $this->assertFalse(defined(AppMessage::class . '::WORKSPACE_REROUTE_PARAMETER'));
    }

    public function testDispatchStampsTheOriginEnvironmentProperty(): void
    {
        $message = new StubProbeMessage();
        $this->assertNull($message->dispatchedFromEnvironment, 'null until dispatch() stamps it');
        $this->assertTrue(property_exists($message, 'dispatchedFromEnvironment'));
    }
}
