<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services;

use DDD\Domain\Common\Entities\Workers\WorkerConsumerDefinition;
use DDD\Domain\Common\Entities\Workers\WorkerDefinitions;
use DDD\Domain\Common\Entities\Workers\WorkerDefinitionSetting;
use DDD\Domain\Common\Entities\Workers\WorkerDefinitionSettings;
use DDD\Domain\Common\Entities\Workers\WorkerLoopDefinition;
use DDD\Domain\Common\Entities\Workers\WorkersCheckFinding;
use DDD\Domain\Common\Entities\Workers\WorkersCheckFindings;
use DDD\Domain\Common\Entities\Workers\WorkersSettings;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The application's worker list: which Messenger transports get workers, and how many. Read from the definition file
 * (`config/system/workers.yaml` unless configured otherwise) the application's release tooling also reads — the
 * framework only reads it:
 *
 *     consumers:
 *       - {transport: send_mail, replicas: 2, limit: 100, memoryLimit: 256M}
 *       - {transport: import_files, min: 0, max: 4, scaleDownAfter: 300, limit: 20, memoryLimit: 1024M}
 *     loops:
 *       - {name: crons, args: 'app:crons:execute', sleepSeconds: 60}
 *
 * {@see self::checkWorkerDefinitions()} compares the list with the transports and routing of the compiled container:
 * a transport without a consumer leaves its messages waiting with no error anywhere.
 */
class WorkerDefinitionsService extends Service
{
    public const string KEY_CONSUMERS = 'consumers';
    public const string KEY_LOOPS = 'loops';
    /** Routing key of Messenger that matches every message */
    public const string ROUTING_WILDCARD = '*';

    protected ?WorkerDefinitions $workerDefinitions = null;

    public function getSettings(): WorkersSettings
    {
        return WorkersSettings::fromEnvironment();
    }

    /** @return string Absolute path of the definition file */
    public function getDefinitionFilePath(): string
    {
        $definitionFile = $this->getSettings()->definitionFile;
        if (str_starts_with($definitionFile, '/')) {
            return $definitionFile;
        }
        return rtrim(DDDService::instance()->getRootDir(), '/') . '/' . $definitionFile;
    }

    /**
     * The worker list. A missing file is an empty list (`definitionFileExists` false); the file is read once per
     * process.
     *
     * @throws InternalErrorException When the file cannot be parsed
     */
    public function getWorkerDefinitions(bool $reload = false): WorkerDefinitions
    {
        if ($this->workerDefinitions !== null && !$reload) {
            return $this->workerDefinitions;
        }
        return $this->workerDefinitions = $this->readWorkerDefinitionsFromFile($this->getDefinitionFilePath());
    }

    /**
     * @throws InternalErrorException When the file cannot be parsed
     */
    public function readWorkerDefinitionsFromFile(string $definitionFilePath): WorkerDefinitions
    {
        $workerDefinitions = new WorkerDefinitions();
        $workerDefinitions->definitionFile = $definitionFilePath;
        if (!is_file($definitionFilePath)) {
            return $workerDefinitions;
        }
        $workerDefinitions->definitionFileExists = true;
        try {
            $parsedDefinitions = Yaml::parseFile($definitionFilePath);
        } catch (Throwable $throwable) {
            throw new InternalErrorException(
                sprintf('Worker definition file %s cannot be parsed: %s', $definitionFilePath, $throwable->getMessage())
            );
        }
        $parsedDefinitions = is_array($parsedDefinitions) ? $parsedDefinitions : [];
        foreach ($this->getEntries($parsedDefinitions, self::KEY_CONSUMERS) as $consumerEntry) {
            $workerConsumerDefinition = new WorkerConsumerDefinition();
            $workerConsumerDefinition->transport = (string)($consumerEntry['transport'] ?? '');
            $workerConsumerDefinition->replicas = $this->getIntOrNull($consumerEntry, 'replicas');
            $workerConsumerDefinition->min = $this->getIntOrNull($consumerEntry, 'min');
            $workerConsumerDefinition->max = $this->getIntOrNull($consumerEntry, 'max');
            $workerConsumerDefinition->scaleDownAfter = $this->getIntOrNull($consumerEntry, 'scaleDownAfter');
            $workerConsumerDefinition->limit = $this->getIntOrNull($consumerEntry, 'limit');
            $workerConsumerDefinition->memoryLimit = isset($consumerEntry['memoryLimit']) ? (string)$consumerEntry['memoryLimit'] : null;
            $workerConsumerDefinition->devOnly = (bool)($consumerEntry['devOnly'] ?? false);
            $workerConsumerDefinition->messageLog = isset($consumerEntry['messageLog']) ? strtolower((string)$consumerEntry['messageLog']) : null;
            $workerConsumerDefinition->additionalSettings = $this->createAdditionalSettings($consumerEntry, WorkerConsumerDefinition::KNOWN_KEYS);
            if ($workerDefinitions->consumers->getByTransport($workerConsumerDefinition->transport) !== null) {
                $workerDefinitions->duplicateTransports[] = $workerConsumerDefinition->transport;
                continue;
            }
            $workerDefinitions->consumers->add($workerConsumerDefinition);
        }
        foreach ($this->getEntries($parsedDefinitions, self::KEY_LOOPS) as $loopEntry) {
            $workerLoopDefinition = new WorkerLoopDefinition();
            $workerLoopDefinition->name = (string)($loopEntry['name'] ?? '');
            $args = $loopEntry['args'] ?? '';
            $workerLoopDefinition->args = is_array($args) ? implode(' ', array_map('strval', $args)) : (string)$args;
            $workerLoopDefinition->sleepSeconds = $this->getIntOrNull($loopEntry, 'sleepSeconds');
            $workerLoopDefinition->prodOnly = (bool)($loopEntry['prodOnly'] ?? false);
            $workerLoopDefinition->additionalSettings = $this->createAdditionalSettings($loopEntry, WorkerLoopDefinition::KNOWN_KEYS);
            $workerDefinitions->loops->add($workerLoopDefinition);
        }
        return $workerDefinitions;
    }

    /**
     * The message-run log mode of a transport: its consumer entry's `messageLog`, else the application's default.
     */
    public function getMessageLogModeForTransport(string $transport): string
    {
        $messageLog = null;
        try {
            $messageLog = $this->getWorkerDefinitions()->consumers->getByTransport($transport)?->messageLog;
        } catch (Throwable) {
            // an unreadable worker list must not stop a worker: the default applies
        }
        return in_array($messageLog, WorkersSettings::MESSAGE_LOG_MODES, true) ? $messageLog : $this->getSettings()->messageLog;
    }

    /**
     * Compares the worker list with the container's Messenger wiring. Errors: a consumed transport without a consumer
     * entry, an entry for a transport that does not exist, a duplicate entry, `min` above `max`, an invalid value, a
     * message routed to a transport that does not exist. Warnings: an entry for a synchronous transport, a routed
     * class that does not exist.
     */
    public function checkWorkerDefinitions(): WorkersCheckFindings
    {
        $workersCheckFindings = new WorkersCheckFindings();
        $workerTransportsService = $this->getWorkerTransportsService();
        $consumedTransportNames = $workerTransportsService->getConsumedTransportNames();
        try {
            $workerDefinitions = $this->getWorkerDefinitions(true);
        } catch (Throwable $throwable) {
            $workersCheckFindings->addFinding(
                WorkersCheckFinding::SEVERITY_ERROR,
                WorkersCheckFinding::CODE_INVALID_VALUE,
                $throwable->getMessage()
            );
            return $workersCheckFindings;
        }
        if (!$workerDefinitions->definitionFileExists && $consumedTransportNames !== []) {
            $workersCheckFindings->addFinding(
                WorkersCheckFinding::SEVERITY_ERROR,
                WorkersCheckFinding::CODE_DEFINITION_FILE_MISSING,
                sprintf(
                    'The worker list %s does not exist, but %d transport(s) need workers: %s',
                    $workerDefinitions->definitionFile,
                    count($consumedTransportNames),
                    implode(', ', $consumedTransportNames)
                )
            );
            return $workersCheckFindings;
        }
        foreach ($workerDefinitions->duplicateTransports as $duplicateTransport) {
            $workersCheckFindings->addFinding(
                WorkersCheckFinding::SEVERITY_ERROR,
                WorkersCheckFinding::CODE_DUPLICATE_CONSUMER,
                sprintf('Transport "%s" has more than one consumer entry', $duplicateTransport),
                $duplicateTransport
            );
        }
        foreach ($consumedTransportNames as $consumedTransportName) {
            if ($workerDefinitions->consumers->getByTransport($consumedTransportName) === null) {
                $workersCheckFindings->addFinding(
                    WorkersCheckFinding::SEVERITY_ERROR,
                    WorkersCheckFinding::CODE_TRANSPORT_WITHOUT_CONSUMER,
                    sprintf('Transport "%s" has no consumer entry: its messages would wait with no worker and no error', $consumedTransportName),
                    $consumedTransportName
                );
            }
        }
        foreach ($workerDefinitions->consumers->getElements() as $workerConsumerDefinition) {
            $transport = $workerConsumerDefinition->transport;
            if ($transport === '') {
                $workersCheckFindings->addFinding(
                    WorkersCheckFinding::SEVERITY_ERROR,
                    WorkersCheckFinding::CODE_INVALID_VALUE,
                    'A consumer entry has no transport'
                );
                continue;
            }
            if (!$workerTransportsService->hasReceiver($transport)) {
                $workersCheckFindings->addFinding(
                    WorkersCheckFinding::SEVERITY_ERROR,
                    WorkersCheckFinding::CODE_CONSUMER_WITHOUT_TRANSPORT,
                    sprintf('Consumer entry "%s" names a transport the Messenger configuration does not have', $transport),
                    $transport
                );
            } elseif ($workerTransportsService->isSynchronousTransport($transport)) {
                $workersCheckFindings->addFinding(
                    WorkersCheckFinding::SEVERITY_WARNING,
                    WorkersCheckFinding::CODE_CONSUMER_OF_SYNC_TRANSPORT,
                    sprintf('Transport "%s" is synchronous here: its workers have nothing to consume', $transport),
                    $transport
                );
            }
            if ($workerConsumerDefinition->min !== null && $workerConsumerDefinition->max !== null
                && $workerConsumerDefinition->min > $workerConsumerDefinition->max) {
                $workersCheckFindings->addFinding(
                    WorkersCheckFinding::SEVERITY_ERROR,
                    WorkersCheckFinding::CODE_MIN_ABOVE_MAX,
                    sprintf('Consumer "%s": min %d is above max %d', $transport, $workerConsumerDefinition->min, $workerConsumerDefinition->max),
                    $transport
                );
            }
            foreach (['replicas' => $workerConsumerDefinition->replicas, 'min' => $workerConsumerDefinition->min, 'max' => $workerConsumerDefinition->max, 'limit' => $workerConsumerDefinition->limit, 'scaleDownAfter' => $workerConsumerDefinition->scaleDownAfter] as $key => $value) {
                if ($value !== null && $value < 0) {
                    $workersCheckFindings->addFinding(
                        WorkersCheckFinding::SEVERITY_ERROR,
                        WorkersCheckFinding::CODE_INVALID_VALUE,
                        sprintf('Consumer "%s": %s must not be negative', $transport, $key),
                        $transport
                    );
                }
            }
            if ($workerConsumerDefinition->messageLog !== null
                && !in_array($workerConsumerDefinition->messageLog, WorkersSettings::MESSAGE_LOG_MODES, true)) {
                $workersCheckFindings->addFinding(
                    WorkersCheckFinding::SEVERITY_ERROR,
                    WorkersCheckFinding::CODE_INVALID_VALUE,
                    sprintf('Consumer "%s": messageLog must be one of %s', $transport, implode(', ', WorkersSettings::MESSAGE_LOG_MODES)),
                    $transport
                );
            }
        }
        foreach ($workerTransportsService->getRoutedMessageClasses() as $routedMessageClass) {
            foreach ($workerTransportsService->getTransportNamesForMessageClass($routedMessageClass) as $routedTransportName) {
                if (!$workerTransportsService->hasReceiver($routedTransportName)) {
                    $workersCheckFindings->addFinding(
                        WorkersCheckFinding::SEVERITY_ERROR,
                        WorkersCheckFinding::CODE_ROUTED_TO_UNKNOWN_TRANSPORT,
                        sprintf('%s is routed to "%s", which is not a transport', $routedMessageClass, $routedTransportName),
                        $routedTransportName
                    );
                }
            }
            if ($routedMessageClass !== self::ROUTING_WILDCARD
                && !class_exists($routedMessageClass) && !interface_exists($routedMessageClass)) {
                $workersCheckFindings->addFinding(
                    WorkersCheckFinding::SEVERITY_WARNING,
                    WorkersCheckFinding::CODE_ROUTED_CLASS_MISSING,
                    sprintf('The routing names %s, which does not exist', $routedMessageClass)
                );
            }
        }
        return $workersCheckFindings;
    }

    protected function getWorkerTransportsService(): WorkerTransportsService
    {
        return DDDService::instance()->getService(WorkerTransportsService::class);
    }

    /**
     * @param array<string, mixed> $parsedDefinitions The parsed file
     * @return array<int, array<string, mixed>> The entries of the list under $key that are maps
     */
    protected function getEntries(array $parsedDefinitions, string $key): array
    {
        $entries = $parsedDefinitions[$key] ?? [];
        return array_values(array_filter(is_array($entries) ? $entries : [], 'is_array'));
    }

    /** @param array<string, mixed> $entry */
    protected function getIntOrNull(array $entry, string $key): ?int
    {
        return isset($entry[$key]) && is_numeric($entry[$key]) ? (int)$entry[$key] : null;
    }

    /**
     * @param array<string, mixed> $entry
     * @param string[] $knownKeys
     */
    protected function createAdditionalSettings(array $entry, array $knownKeys): WorkerDefinitionSettings
    {
        $workerDefinitionSettings = new WorkerDefinitionSettings();
        foreach ($entry as $key => $value) {
            if (in_array($key, $knownKeys, true)) {
                continue;
            }
            $stringValue = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string)$value,
                default => (string)json_encode($value),
            };
            $workerDefinitionSetting = new WorkerDefinitionSetting((string)$key, $stringValue);
            $workerDefinitionSettings->add($workerDefinitionSetting);
        }
        return $workerDefinitionSettings;
    }
}
