<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\DependencyInjection\EnvVarProcessors;

use DDD\Symfony\DependencyInjection\EnvVarProcessors\WorkspaceVhostEnvVarProcessor;
use LogicException;

/**
 * The processor with the broker probe replaced by a canned answer: null means "a probe is a test failure".
 */
class ProbeStubWorkspaceVhostEnvVarProcessor extends WorkspaceVhostEnvVarProcessor
{
    public int $probes = 0;

    public function __construct(
        string $projectDir,
        bool $enabled,
        ?string $workspacesPathSegment,
        protected ?bool $stubbedVhostExists
    ) {
        parent::__construct($projectDir, $enabled, $workspacesPathSegment);
    }

    protected function vhostExists(string $dsn): bool
    {
        $this->probes++;
        if ($this->stubbedVhostExists === null) {
            throw new LogicException('the broker must not be probed here, but was for ' . $dsn);
        }
        return $this->stubbedVhostExists;
    }
}
