<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\AppService;
use DDD\Infrastructure\Services\AuthService;
use LogicException;
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

    /** @var string The workspace on which the AppMessage has been dispatched from */
    public ?string $dispatchedFromWorkspaceDir;

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
            /** @var AppMessage $message */
            $message = new $className();
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
        $this->dispatchedFromWorkspaceDir = AppService::instance()->getRootDir();
        $messageBus = AppService::instance()->getService('messenger.default_bus');
        //$messageBus->dispatch($this, [new AmqpStamp('sync')]);
        $messageBus->dispatch($this);
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
     * Decodes a message from a command line encoded string
     * @param string $commandLineEncodedMessage
     * @return AppMessage|null
     */
    public static function decodeFromCommandline(string $commandLineEncodedMessage): ?AppMessage
    {
        $decompressed = gzuncompress(base64_decode($commandLineEncodedMessage));

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

        $appMessage = new $className();
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

        $appMessage = new $className();
        $appMessage->setPropertiesFromObject($jsonDecodedAppMessage);

        return $appMessage;
    }

    /**
     * Processes the Message on the workspace that is set in the AppMessage by using the symfony console of the
     * given workspace using the ProcessCLIMessage command
     * @param bool $useTempFolderForTransport
     * @return void
     * @throws InternalErrorException
     */
    public function processOnWorkspace(bool $useTempFolderForTransport = true): void
    {
        if (!isset(static::$messageHandler)) {
            throw new InternalErrorException(static::class . ' has no MessageHandler defined');
        }
        if (!$this->dispatchedFromWorkspaceDir) {
            throw new InternalErrorException('The dispatchedFromWorkspacePath is not set.');
        }
        $this->setAccountId();
        $encodedMessage = $useTempFolderForTransport
            ? $this->persistToTempDir()
            : $this->encodeForCommandline();

        $useTempFileOption = $useTempFolderForTransport ? '--useTempFile' : '';
        $consolePath = AppService::instance()->getConsoleDir();
        $command = "php {$this->dispatchedFromWorkspaceDir}{$consolePath} app:process-cli-message {$useTempFileOption} {$encodedMessage} --no-debug";
        //echo $command;
        //die();
        shell_exec($command);
    }

    /**
     * Verifies if the dispatchedFromWorkspaceDir is set and if this workspace is not identical to current one,
     * the AppMessage is processed on the particular workspace. In this case true is returned, else false
     * @param AppMessage $appMessage
     * @return bool
     */
    public function processOnWorkspaceIfNecessary(): bool
    {
        if ($this?->dispatchedFromWorkspaceDir ?? null) {
            return false;
        }
        if ($this->dispatchedFromWorkspaceDir == AppService::instance()->getRootDir()) {
            return false;
        }
        $this->processOnWorkspace();
        return true;
    }
}