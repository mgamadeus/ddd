<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;
use LogicException;
use ReflectionClass;
use ReflectionException;
use Throwable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AppMessage extends ValueObject implements SerializerInterface
{
    /** @var int|null The id of the Account on which behalf the Job is executed */
    public ?int $accountId;

    public static string $messageHandler;

    /** @var string */
    public ?string $tempDirFileName;

    /**
     * @var string|null The project directory the message was dispatched from (`/var/www/dev-workspaces/prj6/app`).
     * Diagnostic: it tells an operator where a message came from; nothing is derived from it any more.
     */
    public ?string $dispatchedFromWorkspaceDir = null;

    /**
     * @var string|null The name of the workspace the message was dispatched from (`prj6`), null outside a workspace
     * (production, a developer's machine) — {@see DDDService::getWorkspaceName()}. Read by
     * {@see \DDD\Symfony\Messenger\Middleware\WorkspaceOriginGuardMiddleware}: a consumer in another workspace REFUSES
     * the message instead of running it on the wrong code. Every workspace has its own broker vhost, so a mismatch is a
     * configuration error worth a failed message, never something to route around.
     */
    public ?string $dispatchedFromEnvironment = null;

    /**
     * Re-creates a serialized message of class $className for hydration through setPropertiesFromObject() — WITHOUT
     * running its constructor.
     *
     * A message class may require constructor arguments (`new AIConversationResumeMessage(int $aiConversationId)`);
     * the serialized form carries every property, so the constructor has nothing left to contribute and
     * `new $className()` only throws "Too few arguments to __construct()". Before this, every cross-workspace
     * reroute of such a message died on the TARGET workspace's console, was retried once and dropped, and the work
     * it carried stayed undone until something external revived the conversation.
     *
     * AppMessage itself declares no constructor, so nothing the base class relies on is skipped, and inline property
     * defaults still apply — they are not constructor work.
     *
     * @param class-string<AppMessage> $className
     * @return AppMessage
     * @throws ReflectionException
     */
    protected static function instantiateForHydration(string $className): AppMessage
    {
        /** @var AppMessage $appMessage */
        $appMessage = (new ReflectionClass($className))->newInstanceWithoutConstructor();
        return $appMessage;
    }

    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        if ($message instanceof AppMessage) {
            return [
                'body' => $message->toJSON(),
                'headers' => ['type' => $message::class],
            ];
        }

        throw new LogicException('Unsupported message type.');
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        // The class name is retrieved from the headers.
        $className = $encodedEnvelope['headers']['type'] ?? null;

        if ($className && is_subclass_of($className, AppMessage::class)) {
            $message = static::instantiateForHydration($className);
            $decodedObject = json_decode($encodedEnvelope['body']);
            $message->setPropertiesFromObject($decodedObject);
            return new Envelope($message);
        }
        throw new LogicException('Unsupported message type.');
    }

    /**
     * Dispatches the Message and processes it on the MessageQueue
     * @return void
     * @throws InternalErrorException
     */
    public function dispatch(): void
    {
        if (!isset(static::$messageHandler)) {
            throw new InternalErrorException(static::class . ' has no MessageHandler defined');
        }
        $this->setAccountId();
        /** @var MessageBusInterface $messageBus */
        $this->dispatchedFromWorkspaceDir = DDDService::instance()->getRootDir();
        $this->dispatchedFromEnvironment = DDDService::instance()->getWorkspaceName();
        $messageBus = DDDService::instance()->getService('messenger.default_bus');
        //$messageBus->dispatch($this, [new AmqpStamp('sync')]);
        $messageBus->dispatch($this);
    }

    /**
     * @deprecated since 2.66.0, removed in 3.0 — does nothing and returns false. The cross-workspace reroute is gone:
     * every checkout has its own broker vhost and a message another checkout dispatched is REFUSED by
     * {@see \DDD\Symfony\Messenger\Middleware\WorkspaceOriginGuardMiddleware} before any handler runs. Delete the
     * `if ($message->processOnWorkspaceIfNecessary()) { return; }` lines from your handlers; the handler shape is
     * `setAuthAccountFromMessage() → work`. Kept for one release only so an app that still calls it does not fatal
     * on the bump.
     */
    public function processOnWorkspaceIfNecessary(): bool
    {
        trigger_deprecation('mgamadeus/ddd', '2.66.0', '%s::processOnWorkspaceIfNecessary() does nothing any more and will be removed in 3.0; delete the call from the handler.', static::class);
        return false;
    }

    public function setAccountId(): void
    {
        $this->accountId = AuthService::instance()->getAccount()?->id ?? null;
    }

    /**
     * Encodes a message to be used as parameter in CLI
     * @return string
     */
    public function encodeForCommandline(): string
    {
        $json = $this->toJSON();
        $compressed = gzcompress($json);
        return base64_encode($compressed);
    }

    /**
     * Decodes a message from a command line encoded string. Returns null for anything that is not one — a truncated
     * or corrupted CLI argument is a realistic failure of the cross-workspace handover, and the caller
     * ({@see \DDD\Symfony\Commands\Base\Messages\ProcessCLIMessage}) turns null into a clean FAILURE exit.
     *
     * @param string $commandLineEncodedMessage
     * @return AppMessage|null
     */
    public static function decodeFromCommandline(string $commandLineEncodedMessage): ?AppMessage
    {
        // gzuncompress() RAISES on malformed input ("data error") instead of just returning false wherever an error
        // handler converts warnings — Symfony's debug handler in a dev workspace does exactly that, and so does a
        // test runner. Without this guard a corrupt argument escaped as an uncaught ErrorException instead of the
        // "Failed to decode message" the command is written to print.
        try {
            $decompressed = @gzuncompress(base64_decode($commandLineEncodedMessage));
        } catch (Throwable) {
            return null;
        }

        if ($decompressed === false) {
            // Handle decompression error
            return null;
        }

        $jsonDecodedAppMessage = json_decode($decompressed);

        $className = $jsonDecodedAppMessage->objectType ?? null;

        if (!$className) {
            // Handle missing class name
            return null;
        }

        if (!class_exists($className) || !is_a($className, AppMessage::class, true)) {
            // Handle non-existing class or wrong classes
            return null;
        }

        $appMessage = static::instantiateForHydration($className);
        $appMessage->setPropertiesFromObject($jsonDecodedAppMessage);

        return $appMessage;
    }

    /**
     * Persists the AppMessage to temporary directory as JSON
     * @return string
     */
    public function persistToTempDir(): string
    {
        $this->tempDirFileName = uniqid('app_message_', true) . '.json';
        $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->tempDirFileName;
        file_put_contents($filePath, $this->toJSON());
        return $this->tempDirFileName;
    }

    /**
     * Loads the AppMessage from the temp directory
     * @param string $tempDirFileName
     * @param bool $deleteTempFileAfterLoad
     * @return AppMessage|null
     */
    public static function loadFromTempDir(string $tempDirFileName, bool $deleteTempFileAfterLoad = true): ?AppMessage
    {
        $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempDirFileName;
        if (!file_exists($filePath)) {
            return null;
        }

        $jsonString = file_get_contents($filePath);
        // Optionally delete the file after loading.
        if ($deleteTempFileAfterLoad) {
            @unlink($filePath);
        }


        $jsonDecodedAppMessage = json_decode($jsonString);
        $className = $jsonDecodedAppMessage->objectType ?? null;

        if (!$className) {
            return null;
        }

        $appMessage = static::instantiateForHydration($className);
        $appMessage->setPropertiesFromObject($jsonDecodedAppMessage);

        return $appMessage;
    }
}
