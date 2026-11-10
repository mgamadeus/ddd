<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Workers;

use DDD\Domain\Common\Entities\Workers\WorkerProcess;
use DDD\Domain\Common\Entities\Workers\WorkersCheckFinding;
use DDD\Domain\Common\Entities\Workers\WorkersCheckFindings;
use DDD\Domain\Common\Entities\Workers\WorkersSettings;
use DDD\Domain\Common\Services\WorkerTransportsService;
use DDD\Tests\Domain\Common\Workers\Fixtures\FixtureWorkerDefinitionsService;
use PHPUnit\Framework\TestCase;

class WorkerDefinitionsServiceTest extends TestCase
{
    protected string $definitionFilePath;

    protected function setUp(): void
    {
        $this->definitionFilePath = sys_get_temp_dir() . '/ddd-workers-' . uniqid('', true) . '.yaml';
    }

    protected function tearDown(): void
    {
        @unlink($this->definitionFilePath);
    }

    protected function createService(string $yaml, ?WorkerTransportsService $workerTransportsService = null): FixtureWorkerDefinitionsService
    {
        file_put_contents($this->definitionFilePath, $yaml);
        return new FixtureWorkerDefinitionsService(
            $this->definitionFilePath,
            $workerTransportsService ?? new WorkerTransportsService(),
        );
    }

    /** @return string[] */
    protected function getCodes(WorkersCheckFindings $workersCheckFindings, string $severity): array
    {
        $codes = [];
        foreach ($workersCheckFindings->getElements() as $workersCheckFinding) {
            if ($workersCheckFinding->severity === $severity) {
                $codes[] = $workersCheckFinding->code . ':' . ($workersCheckFinding->transport ?? '');
            }
        }
        sort($codes);
        return $codes;
    }

    public function testReadsConsumersLoopsAndUnknownKeys(): void
    {
        $workerDefinitions = $this->createService(<<<YAML
            consumers:
              - {transport: send_mail, replicas: 3, limit: 100, memoryLimit: 256M}
              - {transport: import_files, replicas: 1, min: 1, max: 4, scaleDownAfter: 900, limit: 20, memoryLimit: 1024M, memory: 1280Mi, devOnly: true, messageLog: failures}
              - {transport: thumbnails, max: 6}
            loops:
              - {name: crons, args: 'app:crons:execute', sleepSeconds: 60, prodOnly: true, terminationGracePeriodSeconds: 400}
            YAML)->getWorkerDefinitions();

        $this->assertTrue($workerDefinitions->definitionFileExists);
        $this->assertSame(['send_mail', 'import_files', 'thumbnails'], $workerDefinitions->consumers->getTransportNames());

        $fixedConsumer = $workerDefinitions->consumers->getByTransport('send_mail');
        $this->assertFalse($fixedConsumer->isAutoscaled());
        $this->assertSame(3, $fixedConsumer->getEffectiveMin());
        $this->assertSame(3, $fixedConsumer->getEffectiveMax());
        $this->assertSame('256M', $fixedConsumer->memoryLimit);

        $autoscaledConsumer = $workerDefinitions->consumers->getByTransport('import_files');
        $this->assertTrue($autoscaledConsumer->isAutoscaled());
        $this->assertSame(1, $autoscaledConsumer->getEffectiveMin());
        $this->assertSame(4, $autoscaledConsumer->getEffectiveMax());
        $this->assertSame(900, $autoscaledConsumer->scaleDownAfter);
        $this->assertTrue($autoscaledConsumer->devOnly);
        $this->assertSame('failures', $autoscaledConsumer->messageLog);
        $this->assertSame('1280Mi', $autoscaledConsumer->additionalSettings->getValue('memory'));
        $this->assertNull($autoscaledConsumer->additionalSettings->getValue('limit'));

        $this->assertSame(0, $workerDefinitions->consumers->getByTransport('thumbnails')->getEffectiveMin());

        $loop = $workerDefinitions->loops->first();
        $this->assertSame('crons', $loop->name);
        $this->assertSame('app:crons:execute', $loop->args);
        $this->assertSame(60, $loop->sleepSeconds);
        $this->assertTrue($loop->prodOnly);
        $this->assertSame('400', $loop->additionalSettings->getValue('terminationGracePeriodSeconds'));
    }

    public function testAMissingFileIsAnEmptyList(): void
    {
        $workerDefinitionsService = new FixtureWorkerDefinitionsService($this->definitionFilePath, new WorkerTransportsService());
        $workerDefinitions = $workerDefinitionsService->getWorkerDefinitions();
        $this->assertFalse($workerDefinitions->definitionFileExists);
        $this->assertTrue($workerDefinitions->consumers->isEmpty());
        $this->assertTrue($workerDefinitionsService->checkWorkerDefinitions()->isEmpty());
    }

    public function testAMissingFileWithTransportsToConsumeIsAnError(): void
    {
        $workerDefinitionsService = new FixtureWorkerDefinitionsService(
            $this->definitionFilePath,
            new WorkerTransportsService(['send_mail'], [], ['send_mail' => 'amqp://broker/%2f/send_mail'])
        );
        $this->assertSame(
            [WorkersCheckFinding::CODE_DEFINITION_FILE_MISSING . ':'],
            $this->getCodes($workerDefinitionsService->checkWorkerDefinitions(), WorkersCheckFinding::SEVERITY_ERROR)
        );
    }

    public function testCheckPassesWhenListAndTransportsMatch(): void
    {
        $workerTransportsService = new WorkerTransportsService(
            ['send_mail', 'import_files', 'failed', 'inline'],
            ['failed'],
            [
                'send_mail' => 'amqp://broker/%2f/send_mail',
                'import_files' => 'amqp://broker/%2f/import_files',
                'failed' => 'doctrine://default?queue_name=failed',
                'inline' => 'sync://',
            ],
            [self::class => 'send_mail,import_files', '*' => 'send_mail']
        );
        $workersCheckFindings = $this->createService(<<<YAML
            consumers:
              - {transport: send_mail, replicas: 2}
              - {transport: import_files, min: 0, max: 4}
            YAML, $workerTransportsService)->checkWorkerDefinitions();
        $this->assertTrue($workersCheckFindings->isEmpty(), (string)json_encode($workersCheckFindings->toObject()));
    }

    public function testCheckReportsEveryMismatch(): void
    {
        $workerTransportsService = new WorkerTransportsService(
            ['send_mail', 'import_files', 'inline'],
            [],
            ['send_mail' => 'amqp://broker/%2f/send_mail', 'import_files' => 'amqp://broker/%2f/import_files', 'inline' => 'sync://'],
            ['App\\Missing\\GoneMessage' => 'send_mail,nowhere']
        );
        $workersCheckFindings = $this->createService(<<<YAML
            consumers:
              - {transport: send_mail, min: 5, max: 2, messageLog: sometimes}
              - {transport: send_mail, replicas: 1}
              - {transport: removed_transport, replicas: -1}
              - {transport: inline, replicas: 1}
            YAML, $workerTransportsService)->checkWorkerDefinitions();

        $this->assertSame(
            [
                WorkersCheckFinding::CODE_CONSUMER_WITHOUT_TRANSPORT . ':removed_transport',
                WorkersCheckFinding::CODE_DUPLICATE_CONSUMER . ':send_mail',
                WorkersCheckFinding::CODE_INVALID_VALUE . ':removed_transport',
                WorkersCheckFinding::CODE_INVALID_VALUE . ':send_mail',
                WorkersCheckFinding::CODE_MIN_ABOVE_MAX . ':send_mail',
                WorkersCheckFinding::CODE_ROUTED_TO_UNKNOWN_TRANSPORT . ':nowhere',
                WorkersCheckFinding::CODE_TRANSPORT_WITHOUT_CONSUMER . ':import_files',
            ],
            $this->getCodes($workersCheckFindings, WorkersCheckFinding::SEVERITY_ERROR)
        );
        $this->assertSame(
            [
                WorkersCheckFinding::CODE_CONSUMER_OF_SYNC_TRANSPORT . ':inline',
                WorkersCheckFinding::CODE_ROUTED_CLASS_MISSING . ':',
            ],
            $this->getCodes($workersCheckFindings, WorkersCheckFinding::SEVERITY_WARNING)
        );
        $this->assertTrue($workersCheckFindings->hasErrors());
    }

    public function testMessageLogModeFallsBackToTheApplicationDefault(): void
    {
        file_put_contents($this->definitionFilePath, "consumers:\n  - {transport: send_mail, messageLog: off}\n  - {transport: import_files}\n");
        $workerDefinitionsService = new FixtureWorkerDefinitionsService(
            $this->definitionFilePath,
            new WorkerTransportsService(),
            new WorkersSettings(messageLog: WorkersSettings::MESSAGE_LOG_FAILURES)
        );
        $this->assertSame(WorkersSettings::MESSAGE_LOG_OFF, $workerDefinitionsService->getMessageLogModeForTransport('send_mail'));
        $this->assertSame(WorkersSettings::MESSAGE_LOG_FAILURES, $workerDefinitionsService->getMessageLogModeForTransport('import_files'));
        $this->assertSame(WorkersSettings::MESSAGE_LOG_FAILURES, $workerDefinitionsService->getMessageLogModeForTransport('unknown'));
    }

    public function testAConsumerSetsHowLongItsMessagesMayRun(): void
    {
        $workerDefinitionsService = $this->createService(<<<YAML
            consumers:
              - {transport: send_mail, busySilentSeconds: 120}
              - {transport: import_files, busySilentSeconds: 7200}
              - {transport: thumbnails}
              - {transport: broken, busySilentSeconds: 0}
            YAML);
        $consumers = $workerDefinitionsService->getWorkerDefinitions()->consumers;
        $processOf = static function (string $transport): WorkerProcess {
            $workerProcess = new WorkerProcess();
            $workerProcess->transport = $transport;
            return $workerProcess;
        };

        $this->assertSame(120, $consumers->getByTransport('send_mail')->busySilentSeconds);
        $this->assertNull($consumers->getByTransport('broken')->busySilentSeconds);
        $this->assertNull($consumers->getByTransport('send_mail')->additionalSettings->getByUniqueKey('busySilentSeconds'));
        $this->assertSame(120, $workerDefinitionsService->getBusySilentSecondsForWorkerProcess($processOf('send_mail')));
        $this->assertSame(3600, $workerDefinitionsService->getBusySilentSecondsForWorkerProcess($processOf('thumbnails')), 'the setting');
        $this->assertSame(3600, $workerDefinitionsService->getBusySilentSecondsForWorkerProcess($processOf('not_listed')));
        $this->assertSame(7200, $workerDefinitionsService->getBusySilentSecondsForWorkerProcess($processOf('send_mail,import_files')), 'the longest');
        $this->assertSame(3600, $workerDefinitionsService->getBusySilentSecondsForWorkerProcess($processOf('send_mail,thumbnails')));
    }
}
