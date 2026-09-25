<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Entities\MessageHandlers;

use DDD\DDDBundle;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\AuthService;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Throwable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class AppMessage extends ValueObject implements SerializerInterface
{
    /**
     * @var string Container parameter switching the cross-workspace reroute off for a whole installation
     * (default ON when the parameter is absent, like {@see \DDD\Symfony\CompilerPasses\FreshWorkerStateMiddlewarePass::ENABLED_PARAMETER}).
     * A production install running ONE codebase has no workspaces to reroute between and can set it to false.
     */
    public const string WORKSPACE_REROUTE_PARAMETER = 'ddd.messenger.workspace_reroute';

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
        $this->dispatchedFromWorkspaceDir = DDDService::instance()->getRootDir();
        $messageBus = DDDService::instance()->getService('messenger.default_bus');
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

        $consolePath = DDDService::instance()->getConsoleDir();
        // Argument LIST, not a shell string: the encoded message travels unquoted and never reaches a shell.
        $consoleCommand = ['php', $this->dispatchedFromWorkspaceDir . $consolePath, 'app:process-cli-message'];
        if ($useTempFolderForTransport) {
            $consoleCommand[] = '--useTempFile';
        }
        $consoleCommand[] = $encodedMessage;
        $consoleCommand[] = '--no-debug';

        $process = $this->runWorkspaceConsoleProcess($consoleCommand);
        if (!$process->isSuccessful()) {
            // shell_exec() discarded the exit status, so a failure in the rerouted process was invisible: the
            // consumer acked the message as handled. Throwing hands it back to Messenger's retry / failure
            // transport, where a failed job belongs.
            $processOutput = trim($process->getErrorOutput() !== '' ? $process->getErrorOutput() : $process->getOutput());
            throw new InternalErrorException(
                sprintf(
                    '%s failed on workspace %s with exit code %s: %s',
                    static::class,
                    $this->dispatchedFromWorkspaceDir,
                    (string)$process->getExitCode(),
                    mb_substr($processOutput, 0, 2000)
                )
            );
        }
    }

    /**
     * Runs the target workspace's console command to completion and returns the finished process. Seam: a test
     * substitutes the process instead of starting a console.
     * @param string[] $consoleCommand
     * @return Process
     */
    protected function runWorkspaceConsoleProcess(array $consoleCommand): Process
    {
        $process = new Process($consoleCommand);
        // The child runs the WHOLE handler on the other workspace; Process' 60s default would kill long jobs that
        // shell_exec() used to run to completion.
        $process->setTimeout(null);
        $process->run();
        return $process;
    }

    /**
     * Re-executes this message on the workspace it was DISPATCHED from, when that is a different workspace than the
     * one consuming it: {@see self::processOnWorkspace()} runs it through that workspace's console, so the right
     * code runs against the right database. Returns true when the message was rerouted (the caller stops there) and
     * false when it is to be processed locally — the shape every handler follows:
     * `setAuthAccountFromMessage() → if (processOnWorkspaceIfNecessary()) return; → work`.
     *
     * Processed locally (false) when:
     *  - no dispatch workspace is recorded (a message built outside {@see self::dispatch()}, e.g. a CLI-encoded one);
     *  - the reroute is switched off for the installation ({@see self::WORKSPACE_REROUTE_PARAMETER});
     *  - the recorded directory no longer exists (a stale release dir after a deploy switch, a foreign host) —
     *    logged at warning level, because the message was serialised by the same codebase family and local
     *    processing is the safe default;
     *  - the recorded directory IS the current workspace. Both sides go through realpath() first: kernel.project_dir
     *    is symlink-resolved by Symfony while the stored string may not be, and a plain string mismatch on identical
     *    directories would reroute EVERY message through a child process.
     *
     * No loop guard is needed and none should be added: inside the rerouted console process the current root dir IS
     * the recorded one, so this method returns false there and the handler does the work.
     *
     * @return bool true when the message was rerouted to another workspace and must not be processed here
     * @throws InternalErrorException when the rerouted console process fails
     */
    public function processOnWorkspaceIfNecessary(): bool
    {
        $dispatchedFromWorkspaceDir = $this->dispatchedFromWorkspaceDir ?? null;
        if (!$dispatchedFromWorkspaceDir) {
            return false;
        }
        if (!static::workspaceRerouteIsEnabled()) {
            return false;
        }
        $dispatchedFromWorkspaceRealPath = realpath($dispatchedFromWorkspaceDir);
        if ($dispatchedFromWorkspaceRealPath === false) {
            $this->getWorkspaceRerouteLogger()?->warning(
                static::class . ' was dispatched from a workspace directory that no longer exists, processing it'
                . ' locally instead of rerouting: ' . $dispatchedFromWorkspaceDir
            );
            return false;
        }
        if ($dispatchedFromWorkspaceRealPath === realpath($this->getCurrentWorkspaceDir())) {
            return false;
        }
        $this->processOnWorkspace();
        return true;
    }

    /**
     * @return string The workspace directory of the process consuming the message (overridable seam for tests)
     */
    protected function getCurrentWorkspaceDir(): string
    {
        return DDDService::instance()->getRootDir();
    }

    /**
     * @return bool Whether cross-workspace rerouting is enabled for this installation; true when the container
     * carries no {@see self::WORKSPACE_REROUTE_PARAMETER} parameter at all, which is every app that never opts out
     */
    protected static function workspaceRerouteIsEnabled(): bool
    {
        try {
            $container = DDDBundle::getContainer();
            if (!$container->hasParameter(self::WORKSPACE_REROUTE_PARAMETER)) {
                return true;
            }
            return (bool)$container->getParameter(self::WORKSPACE_REROUTE_PARAMETER);
        } catch (Throwable) {
            // no container (a standalone script, a boot-time dispatch): the documented default applies
            return true;
        }
    }

    /**
     * @return LoggerInterface|null The logger, or null when no container is available — a missing logger must never
     * turn "process locally" into a fatal
     */
    protected function getWorkspaceRerouteLogger(): ?LoggerInterface
    {
        try {
            return DDDService::instance()->getLogger();
        } catch (Throwable) {
            return null;
        }
    }
}