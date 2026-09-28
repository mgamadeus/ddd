<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Commands\Base\Messages;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Symfony\Commands\Base\Messages\ProcessCLIMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use TestContainer;

#[AsMessageHandler(fromTransport: 'ai_conversation_turn')]
class TransportBoundProbeHandler extends AppMessageHandler
{
    public function __construct()
    {
    }

    public function __invoke(TransportBoundProbeMessage $message): void
    {
    }
}

class UnboundProbeHandler extends AppMessageHandler
{
    public function __construct()
    {
    }
}

class TransportBoundProbeMessage extends AppMessage
{
    public static string $messageHandler = TransportBoundProbeHandler::class;
}

class UnboundProbeMessage extends AppMessage
{
    public static string $messageHandler = UnboundProbeHandler::class;
}

class ProcessCLIMessageTransportNameTest extends TestCase
{
    public function testTheHandlersOwnTransportNameIsUsed(): void
    {
        $this->assertSame(
            'ai_conversation_turn',
            ProcessCLIMessage::receivedFromTransportNameFor(TransportBoundProbeHandler::class)
        );
    }

    public function testAHandlerWithoutATransportKeepsThePlainLabel(): void
    {
        $this->assertSame(
            ProcessCLIMessage::RECEIVED_FROM_TRANSPORT_NAME,
            ProcessCLIMessage::receivedFromTransportNameFor(UnboundProbeHandler::class)
        );
    }

    public function testMessengerWouldActuallyFindTheHandlerForThatStamp(): void
    {
        // the real filter: HandlersLocator::shouldHandle() compares the stamp's transport with from_transport
        $locator = new HandlersLocator([
            TransportBoundProbeMessage::class => [
                new HandlerDescriptor(new TransportBoundProbeHandler(), ['from_transport' => 'ai_conversation_turn']),
            ],
        ]);
        $message = new TransportBoundProbeMessage();

        $stampedAsTheCommandDoes = new Envelope($message, [
            new ReceivedStamp(ProcessCLIMessage::receivedFromTransportNameFor(TransportBoundProbeHandler::class)),
        ]);
        $this->assertCount(1, iterator_to_array($locator->getHandlers($stampedAsTheCommandDoes)),
            'the handler must be found for the stamp the command writes');

        $stampedWithThePlainLabel = new Envelope($message, [
            new ReceivedStamp(ProcessCLIMessage::RECEIVED_FROM_TRANSPORT_NAME),
        ]);
        $this->assertCount(0, iterator_to_array($locator->getHandlers($stampedWithThePlainLabel)),
            'the plain label finds nothing — this is exactly what broke in v2.65.2');
    }

    public function testTheCommandStampsTheDerivedTransportName(): void
    {
        $bus = new RecordingMessageBus();
        TestContainer::$overrides['messenger.default_bus'] = $bus;
        try {
            $message = new TransportBoundProbeMessage();
            $application = new Application();
            $application->add(new ProcessCLIMessage());
            $commandTester = new CommandTester($application->find('app:process-cli-message'));
            $commandTester->execute(['message' => $message->encodeForCommandline()]);

            $this->assertSame(0, $commandTester->getStatusCode());
            $this->assertSame(
                'ai_conversation_turn',
                $bus->dispatchedEnvelopes[0]->last(ReceivedStamp::class)->getTransportName()
            );
        } finally {
            TestContainer::$overrides = [];
        }
    }
}
