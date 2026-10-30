<?php

declare (strict_types=1);

namespace DDD\Symfony\Commands\Base\Messages;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use ReflectionClass;
use ReflectionException;
use Throwable;

#[AsCommand(
    name: 'app:process-cli-message',
    description: 'Processes a Message encoded as CLI parameter and executes the corresponding MessageHandler',
    hidden: false
)]
class ProcessCLIMessage extends Command
{
    /**
     * @var string The transport name the ReceivedStamp carries — a label for logs and middleware, not a real
     * transport: nothing is queued here, the envelope is handled in this process.
     */
    public const string RECEIVED_FROM_TRANSPORT_NAME = 'app:process-cli-message';

    /**
     * The transport name the ReceivedStamp must carry for $handlerClass to be found.
     *
     * A handler registered with `#[AsMessageHandler(fromTransport: 'x')]` handles ONLY envelopes received from
     * transport `x`: Messenger's HandlersLocator::shouldHandle() compares the stamp's transport name with the
     * handler's `from_transport` option and skips the handler on a mismatch. Stamping a generic label therefore
     * ends in "No handler for message" — which is what v2.65.2 did to every rerouted turn whose handler names a
     * transport, and applications name one throughout. The handler's own attribute is the answer; a handler
     * without `fromTransport` is found under any name, so the plain label stays for it.
     *
     * Limitation worth knowing: this reads the ATTRIBUTE. A handler whose `from_transport` is set through a DI tag
     * in services configuration instead is not visible here and would still need the label to match.
     *
     * @param class-string $handlerClass
     * @return string
     * @throws ReflectionException
     */
    public static function receivedFromTransportNameFor(string $handlerClass): string
    {
        foreach ((new ReflectionClass($handlerClass))->getAttributes(AsMessageHandler::class) as $attribute) {
            $fromTransport = $attribute->newInstance()->fromTransport;
            if (is_string($fromTransport) && $fromTransport !== '') {
                return $fromTransport;
            }
        }
        return self::RECEIVED_FROM_TRANSPORT_NAME;
    }

    protected function configure(): void
    {
        $this->addArgument('message', InputArgument::REQUIRED, 'The encoded message or temp file name.');
        $this->addOption('useTempFile', null, InputOption::VALUE_NONE, 'Use temp file for transport.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $useTempFile = $input->getOption('useTempFile');

        if ($useTempFile) {
            $message = AppMessage::loadFromTempDir($input->getArgument('message'));
        } else {
            $message = AppMessage::decodeFromCommandline($input->getArgument('message'));
        }

        if (!$message) {
            $output->writeln('<error>Failed to decode message.</error>');
            return Command::FAILURE;
        }

        $handlerClass = $message::$messageHandler;
        if (!is_a($handlerClass, AppMessageHandler::class, true)) {
            $output->writeln("<error>Invalid handler class: $handlerClass.</error>");
            return Command::FAILURE;
        }

        // The message goes through the BUS, as a RECEIVED one, instead of calling the handler directly. Everything
        // the consuming worker would have run then runs here too: the application's own middleware (an AI usage
        // envelope, for instance) and Core's FreshWorkerStateMiddleware. Calling `new $handlerClass()` skipped every
        // middleware, so a rerouted turn reached its first model call without the envelope its handler assumes and
        // failed the work it was carrying.
        //
        // ReceivedStamp is what makes this "consume it here": Symfony's SendMessageMiddleware explicitly does not
        // send an envelope that carries one back to a transport (SendMessageMiddleware::handle()), and
        // HandleMessageMiddleware runs the handler inline — the same path a worker takes after messenger:consume
        // received the message. messenger.default_bus is the bus AppMessage::dispatch() uses, so the middleware set
        // is the one the application configured for its handlers.
        //
        // The handler resolves through the bus now, so a message class whose handler is not registered with
        // Messenger fails here with NoHandlerForMessageException instead of being constructed by hand. That is
        // visible and exits FAILURE rather than failing silently later.
        /** @var MessageBusInterface $messageBus */
        $messageBus = DDDService::instance()->getService('messenger.default_bus');
        try {
            // ConsumedByWorkerStamp: this process is the consumer of the message, so the worker-only middleware
            // (FreshWorkerStateMiddleware's reset and post-job rollback) runs as it does under messenger:consume
            $messageBus->dispatch(new Envelope($message, [
                new ReceivedStamp(self::receivedFromTransportNameFor($handlerClass)),
                new ConsumedByWorkerStamp(),
            ]));
        } catch (Throwable $throwable) {
            // The rerouting parent reads this exit code and fails its own message, so the transport's retry
            // re-delivers it; the text names the handler that failed and why.
            $output->writeln('<error>' . $handlerClass . ' failed: ' . $throwable->getMessage() . '</error>');
            $this->logHandlerFailureToIssues($throwable, $handlerClass);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * The handler's own error (unwrapped from Messenger's HandlerFailedException) into the issues log — here, where its
     * stack trace still exists; the parent only sees this command's exit code. Never throws.
     */
    protected function logHandlerFailureToIssues(Throwable $throwable, string $handlerClass): void
    {
        try {
            $handlerThrowable = $throwable instanceof HandlerFailedException
                ? ($throwable->getWrappedExceptions()[array_key_first($throwable->getWrappedExceptions())] ?? $throwable)
                : $throwable;
            /** @var IssuesLogService $issuesLogService */
            $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
            $issuesLogService->logThrowable(
                $handlerThrowable,
                LogLevel::CRITICAL,
                [AppMessageHandler::ISSUES_CONTEXT_CUSTOM_MESSAGE => $handlerClass . ' failed (run by ' . $this->getName() . ')', 'error.handled' => false],
                false,
                IssuesLogService::LOG_MESSAGE_SECTION_MESSENGER,
            );
        } catch (Throwable) {
            // the exit code still reports the failure
        }
    }
}
