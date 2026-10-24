<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use TestContainer;

class StampsProbeMessage extends AppMessage
{
    public static string $messageHandler = 'StampsProbeHandler';
}

/** Records the stamps the message was dispatched with instead of running a bus. */
class StampRecordingMessageBus implements MessageBusInterface
{
    /** @var array Stamps of the last dispatch */
    public array $lastStamps = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->lastStamps = $stamps;
        return new Envelope($message, $stamps);
    }
}

/** A caller that needs a stamp (DelayStamp for a delayed job) passes it to dispatch() instead of re-implementing it. */
class AppMessageDispatchStampsTest extends TestCase
{
    protected StampRecordingMessageBus $messageBus;

    protected function setUp(): void
    {
        $this->messageBus = new StampRecordingMessageBus();
        TestContainer::$overrides['messenger.default_bus'] = $this->messageBus;
    }

    protected function tearDown(): void
    {
        TestContainer::$overrides = [];
    }

    public function testTheStampsReachTheBus(): void
    {
        $delayStamp = new DelayStamp(5000);

        (new StampsProbeMessage())->dispatch([$delayStamp]);

        $this->assertSame([$delayStamp], $this->messageBus->lastStamps);
    }

    public function testWithoutStampsNothingIsAdded(): void
    {
        (new StampsProbeMessage())->dispatch();

        $this->assertSame([], $this->messageBus->lastStamps);
    }
}
