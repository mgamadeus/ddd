<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Base\Entities\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/** Records the reroute instead of starting a console process, and the warning instead of writing a log. */
class WorkspaceRerouteProbeMessage extends AppMessage
{
    public int $processOnWorkspaceCallCount = 0;

    public string $currentWorkspaceDir = '';

    /** @var string[] */
    public array $loggedWarnings = [];

    public function processOnWorkspace(bool $useTempFolderForTransport = true): void
    {
        $this->processOnWorkspaceCallCount++;
    }

    protected function getCurrentWorkspaceDir(): string
    {
        return $this->currentWorkspaceDir;
    }

    protected function getWorkspaceRerouteLogger(): ?\Psr\Log\LoggerInterface
    {
        $probeMessage = $this;
        return new class ($probeMessage) extends AbstractLogger {
            public function __construct(protected WorkspaceRerouteProbeMessage $probeMessage)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->probeMessage->loggedWarnings[] = (string)$level . ': ' . (string)$message;
            }
        };
    }
}

class AppMessageWorkspaceRerouteTest extends TestCase
{
    protected string $workspaceDir;

    protected string $otherWorkspaceDir;

    protected string $workspaceSymlink;

    protected function setUp(): void
    {
        $temporaryRoot = sys_get_temp_dir() . '/ddd_workspace_reroute_' . bin2hex(random_bytes(4));
        $this->workspaceDir = $temporaryRoot . '/current';
        $this->otherWorkspaceDir = $temporaryRoot . '/other';
        $this->workspaceSymlink = $temporaryRoot . '/current-link';
        mkdir($this->workspaceDir, 0777, true);
        mkdir($this->otherWorkspaceDir, 0777, true);
        symlink($this->workspaceDir, $this->workspaceSymlink);
        dddTestContainer()->getParameterBag()->remove(AppMessage::WORKSPACE_REROUTE_PARAMETER);
    }

    protected function tearDown(): void
    {
        @unlink($this->workspaceSymlink);
        @rmdir($this->workspaceDir);
        @rmdir($this->otherWorkspaceDir);
        @rmdir(dirname($this->workspaceDir));
        dddTestContainer()->getParameterBag()->remove(AppMessage::WORKSPACE_REROUTE_PARAMETER);
    }

    protected function probeMessage(?string $dispatchedFromWorkspaceDir): WorkspaceRerouteProbeMessage
    {
        $probeMessage = new WorkspaceRerouteProbeMessage();
        $probeMessage->dispatchedFromWorkspaceDir = $dispatchedFromWorkspaceDir;
        $probeMessage->currentWorkspaceDir = $this->workspaceDir;
        return $probeMessage;
    }

    public function testNoDispatchWorkspaceMeansLocalProcessing(): void
    {
        $probeMessage = $this->probeMessage(null);
        $this->assertFalse($probeMessage->processOnWorkspaceIfNecessary());
        $this->assertSame(0, $probeMessage->processOnWorkspaceCallCount);
    }

    public function testSameWorkspaceMeansLocalProcessing(): void
    {
        $probeMessage = $this->probeMessage($this->workspaceDir);
        $this->assertFalse($probeMessage->processOnWorkspaceIfNecessary());
        $this->assertSame(0, $probeMessage->processOnWorkspaceCallCount);
    }

    public function testSameWorkspaceThroughASymlinkMeansLocalProcessing(): void
    {
        // the realpath rail: kernel.project_dir is symlink-resolved, the stored string may not be
        $probeMessage = $this->probeMessage($this->workspaceSymlink);
        $this->assertFalse($probeMessage->processOnWorkspaceIfNecessary());
        $this->assertSame(0, $probeMessage->processOnWorkspaceCallCount);
    }

    public function testAnotherExistingWorkspaceIsRerouted(): void
    {
        $probeMessage = $this->probeMessage($this->otherWorkspaceDir);
        $this->assertTrue($probeMessage->processOnWorkspaceIfNecessary());
        $this->assertSame(1, $probeMessage->processOnWorkspaceCallCount);
    }

    public function testAWorkspaceThatNoLongerExistsIsProcessedLocallyWithAWarning(): void
    {
        $goneWorkspaceDir = $this->otherWorkspaceDir . '/release-2026-09-01-gone';
        $probeMessage = $this->probeMessage($goneWorkspaceDir);
        $this->assertFalse($probeMessage->processOnWorkspaceIfNecessary());
        $this->assertSame(0, $probeMessage->processOnWorkspaceCallCount);
        $this->assertCount(1, $probeMessage->loggedWarnings);
        $this->assertStringContainsString('warning', $probeMessage->loggedWarnings[0]);
        $this->assertStringContainsString($goneWorkspaceDir, $probeMessage->loggedWarnings[0]);
    }

    public function testTheParameterSwitchesTheRerouteOff(): void
    {
        dddTestContainer()->setParameter(AppMessage::WORKSPACE_REROUTE_PARAMETER, false);
        $probeMessage = $this->probeMessage($this->otherWorkspaceDir);
        $this->assertFalse($probeMessage->processOnWorkspaceIfNecessary());
        $this->assertSame(0, $probeMessage->processOnWorkspaceCallCount);
    }

    public function testTheRerouteIsOnWhenTheParameterIsAbsentOrTrue(): void
    {
        $probeMessage = $this->probeMessage($this->otherWorkspaceDir);
        $this->assertTrue($probeMessage->processOnWorkspaceIfNecessary(), 'absent parameter must mean enabled');

        dddTestContainer()->setParameter(AppMessage::WORKSPACE_REROUTE_PARAMETER, true);
        $probeMessage = $this->probeMessage($this->otherWorkspaceDir);
        $this->assertTrue($probeMessage->processOnWorkspaceIfNecessary());
    }
}
