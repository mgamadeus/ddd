<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Commands\Base\Messages;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Symfony\Commands\Base\Messages\ProcessCLIMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use TestContainer;

class CliProbeHandler extends AppMessageHandler
{
    public function __construct()
    {
    }
}

class CliProbeMessage extends AppMessage
{
    public static string $messageHandler = CliProbeHandler::class;

    public int $payload;

    public function __construct(int $payload)
    {
        parent::__construct();
        $this->payload = $payload;
    }
}

/** Stands in for messenger.default_bus: records what was dispatched instead of running middleware for real. */
class RecordingMessageBus implements MessageBusInterface
{
    /** @var Envelope[] */
    public array $dispatchedEnvelopes = [];

    public bool $throwOnDispatch = false;

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message, $stamps);
        $this->dispatchedEnvelopes[] = $envelope;
        if ($this->throwOnDispatch) {
            throw new RuntimeException('handler blew up');
        }
        return $envelope;
    }
}

class ProcessCLIMessageBusDispatchTest extends TestCase
{
    protected RecordingMessageBus $bus;

    protected function setUp(): void
    {
        $this->bus = new RecordingMessageBus();
        TestContainer::$overrides['messenger.default_bus'] = $this->bus;
    }

    protected function tearDown(): void
    {
        TestContainer::$overrides = [];
    }

    protected function runCommand(string $encodedMessage): CommandTester
    {
        $application = new Application();
        $application->add(new ProcessCLIMessage());
        $commandTester = new CommandTester($application->find('app:process-cli-message'));
        $commandTester->execute(['message' => $encodedMessage]);
        return $commandTester;
    }

    public function testTheMessageGoesThroughTheBusAsAReceivedEnvelope(): void
    {
        $message = new CliProbeMessage(4711);
        $commandTester = $this->runCommand($message->encodeForCommandline());

        $this->assertSame(0, $commandTester->getStatusCode(), 'SUCCESS');
        $this->assertCount(1, $this->bus->dispatchedEnvelopes, 'dispatched exactly once');

        $envelope = $this->bus->dispatchedEnvelopes[0];
        $this->assertInstanceOf(CliProbeMessage::class, $envelope->getMessage());
        $this->assertSame(4711, $envelope->getMessage()->payload, 'the payload survived the CLI transport');

        $receivedStamp = $envelope->last(ReceivedStamp::class);
        $this->assertNotNull($receivedStamp, 'without a ReceivedStamp the sender middleware would re-queue it');
        $this->assertSame(ProcessCLIMessage::RECEIVED_FROM_TRANSPORT_NAME, $receivedStamp->getTransportName());
    }

    public function testAFailingDispatchExitsFailureAndNamesTheHandler(): void
    {
        $this->bus->throwOnDispatch = true;
        $message = new CliProbeMessage(1);

        $commandTester = $this->runCommand($message->encodeForCommandline());

        $this->assertSame(1, $commandTester->getStatusCode(), 'FAILURE, so the rerouting parent fails its message');
        $this->assertStringContainsString(CliProbeHandler::class, $commandTester->getDisplay());
        $this->assertStringContainsString('handler blew up', $commandTester->getDisplay());
    }

    public function testAnUndecodableMessageStillFailsBeforeTheBus(): void
    {
        $commandTester = $this->runCommand('not-a-message');

        $this->assertSame(1, $commandTester->getStatusCode());
        $this->assertStringContainsString('Failed to decode', $commandTester->getDisplay());
        $this->assertSame([], $this->bus->dispatchedEnvelopes, 'nothing is dispatched for an undecodable message');
    }
}
