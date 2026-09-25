<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Substitutes the console child by a real, trivial process, so the exit-code handling is exercised for real. */
class ExitCodeProbeMessage extends AppMessage
{
    public static string $messageHandler = 'ExitCodeProbeHandler';

    /** @var string[] The command the child would have been started with */
    public array $capturedConsoleCommand = [];

    public array $substituteProcessCommand = ['php', '-r', 'exit(0);'];

    protected function runWorkspaceConsoleProcess(array $consoleCommand): Process
    {
        $this->capturedConsoleCommand = $consoleCommand;
        $process = new Process($this->substituteProcessCommand);
        $process->run();
        return $process;
    }

    public function encodeForCommandline(): string
    {
        return 'ENCODED_MESSAGE';
    }
}

class AppMessageWorkspaceProcessExitCodeTest extends TestCase
{
    public function testAFailingChildProcessThrowsSoMessengerCanRetry(): void
    {
        $probeMessage = new ExitCodeProbeMessage();
        $probeMessage->dispatchedFromWorkspaceDir = '/var/www/dev-workspaces/other/app';
        $probeMessage->substituteProcessCommand = ['php', '-r', 'fwrite(STDERR, "handler blew up"); exit(3);'];

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessageMatches('/exit code 3/');
        $probeMessage->processOnWorkspace(useTempFolderForTransport: false);
    }

    public function testASuccessfulChildProcessIsSilent(): void
    {
        $probeMessage = new ExitCodeProbeMessage();
        $probeMessage->dispatchedFromWorkspaceDir = '/var/www/dev-workspaces/other/app';
        $probeMessage->processOnWorkspace(useTempFolderForTransport: false);

        $this->assertContains('app:process-cli-message', $probeMessage->capturedConsoleCommand);
        $this->assertContains('--no-debug', $probeMessage->capturedConsoleCommand);
        $this->assertNotContains('--useTempFile', $probeMessage->capturedConsoleCommand);
        $this->assertContains('ENCODED_MESSAGE', $probeMessage->capturedConsoleCommand);
        $this->assertNotContains('', $probeMessage->capturedConsoleCommand, 'no empty argv element');
    }
}
