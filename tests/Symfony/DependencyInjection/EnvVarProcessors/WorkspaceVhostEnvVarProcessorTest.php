<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\DependencyInjection\EnvVarProcessors;

use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\DependencyInjection\EnvVarProcessors\WorkspaceVhostEnvVarProcessor;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The processor with the broker probe replaced by a canned answer: null means "a probe is a test failure".
 */
final class ProbeStubWorkspaceVhostEnvVarProcessor extends WorkspaceVhostEnvVarProcessor
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

class WorkspaceVhostEnvVarProcessorTest extends TestCase
{
    protected const string DSN = 'amqp://user:pw@rabbit:5672/%2f';

    protected function processorFor(
        string $projectDir,
        bool $enabled = true,
        ?string $segment = null,
        ?bool $vhostExists = true
    ): ProbeStubWorkspaceVhostEnvVarProcessor {
        return new ProbeStubWorkspaceVhostEnvVarProcessor($projectDir, $enabled, $segment, $vhostExists);
    }

    protected function resolve(WorkspaceVhostEnvVarProcessor $processor, string $dsn = self::DSN): string
    {
        return $processor->getEnv(WorkspaceVhostEnvVarProcessor::PREFIX, 'MESSENGER_TRANSPORT_DSN', fn () => $dsn);
    }

    public function testInsideAWorkspaceTheVhostBecomesTheWorkspaceName(): void
    {
        $processor = $this->processorFor('/var/www/dev-workspaces/prj6/app');
        $this->assertSame('amqp://user:pw@rabbit:5672/prj6', $this->resolve($processor));
        $this->assertSame(1, $processor->probes, 'inside a workspace the broker is probed once');
    }

    public function testOutsideAWorkspaceTheDsnIsUntouchedAndNothingIsProbed(): void
    {
        // production keeps its own vhost — nothing is derived and no broker is contacted where there is no workspace
        $processor = $this->processorFor('/var/www/rc-app-backend/app', vhostExists: null);
        $this->assertSame(self::DSN, $this->resolve($processor));
        $this->assertSame(0, $processor->probes);
    }

    public function testTheParameterSwitchesItOffAndNothingIsProbed(): void
    {
        $processor = $this->processorFor('/var/www/dev-workspaces/prj6/app', enabled: false, vhostExists: null);
        $this->assertSame(self::DSN, $this->resolve($processor));
        $this->assertSame(0, $processor->probes);
    }

    public function testAMissingWorkspaceVhostReturnsTheEnvDsnUnchanged(): void
    {
        // never a hardcoded "/": the .env DSN as written, query string included
        $processor = $this->processorFor('/var/www/dev-workspaces/prj6/app', vhostExists: false);
        $this->assertSame(self::DSN, $this->resolve($processor));
        $this->assertSame(
            'amqp://user:pw@rabbit:5672/%2f?heartbeat=30',
            $this->resolve($processor, 'amqp://user:pw@rabbit:5672/%2f?heartbeat=30')
        );
    }

    public function testAQueryStringSurvivesAndADsnWithoutAVhostGetsOne(): void
    {
        $processor = $this->processorFor('/var/www/dev-workspaces/mgn/app');
        $this->assertSame(
            'amqp://user:pw@rabbit:5672/mgn?heartbeat=30',
            $this->resolve($processor, 'amqp://user:pw@rabbit:5672/%2f?heartbeat=30')
        );
        $this->assertSame(
            'amqp://user:pw@rabbit:5672/mgn',
            $this->resolve($processor, 'amqp://user:pw@rabbit:5672')
        );
    }

    public function testTheConfiguredPathSegmentWins(): void
    {
        // the parameter is the source of truth; DDDService::WORKSPACES_PATH_SEGMENT is only its default
        $this->assertSame(
            'amqp://user:pw@rabbit:5672/alpha',
            $this->resolve($this->processorFor('/srv/checkouts/alpha/app', segment: '/checkouts/'))
        );
        $this->assertSame(
            self::DSN,
            $this->resolve($this->processorFor('/var/www/dev-workspaces/prj6/app', segment: '/checkouts/', vhostExists: null)),
            'with a different segment configured, the default layout is no longer a workspace'
        );
    }

    public function testOnlyAMissingVhostCountsAsNotFound(): void
    {
        $this->assertTrue(WorkspaceVhostEnvVarProcessor::isVhostNotFound(
            'Server connection error: 530, message: NOT_ALLOWED - vhost adk not found'
        ));
        $this->assertFalse(WorkspaceVhostEnvVarProcessor::isVhostNotFound(
            "Server connection error: 530, message: NOT_ALLOWED - access to vhost 'adk' refused for user 'rc'"
        ), 'a refused access to an EXISTING vhost is also 530 NOT_ALLOWED and must surface');
        $this->assertFalse(WorkspaceVhostEnvVarProcessor::isVhostNotFound(
            'Socket error: could not connect to host.'
        ));
        $this->assertFalse(WorkspaceVhostEnvVarProcessor::isVhostNotFound(
            'Server connection error: 403, message: ACCESS_REFUSED - Login was refused using authentication mechanism PLAIN'
        ));
    }

    public function testTheDerivationIsTheOneDDDServiceUses(): void
    {
        $this->assertSame('prj6', DDDService::workspaceNameFromRootDir('/var/www/dev-workspaces/prj6/app'));
        $this->assertNull(DDDService::workspaceNameFromRootDir('/var/www/rc-app-backend/app'));
        $this->assertSame('alpha', DDDService::workspaceNameFromRootDir('/srv/checkouts/alpha/app', '/checkouts/'));
        $this->assertSame(
            DDDService::WORKSPACES_PATH_SEGMENT,
            '/dev-workspaces/',
            'the constant is the documented default of ' . WorkspaceVhostEnvVarProcessor::PATH_SEGMENT_PARAMETER
        );
    }

    public function testTheProcessorAdvertisesItsPrefix(): void
    {
        $this->assertSame(['workspace_vhost' => 'string'], WorkspaceVhostEnvVarProcessor::getProvidedTypes());
    }
}
