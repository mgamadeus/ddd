<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\DependencyInjection\EnvVarProcessors;

use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\DependencyInjection\EnvVarProcessors\WorkspaceVhostEnvVarProcessor;
use PHPUnit\Framework\TestCase;

class WorkspaceVhostEnvVarProcessorTest extends TestCase
{
    protected const string DSN = 'amqp://user:pw@rabbit:5672/%2f';

    protected function processorFor(string $projectDir, bool $enabled = true, ?string $segment = null): WorkspaceVhostEnvVarProcessor
    {
        return new WorkspaceVhostEnvVarProcessor($projectDir, $enabled, $segment);
    }

    protected function resolve(WorkspaceVhostEnvVarProcessor $processor, string $dsn = self::DSN): string
    {
        return $processor->getEnv(WorkspaceVhostEnvVarProcessor::PREFIX, 'MESSENGER_TRANSPORT_DSN', fn () => $dsn);
    }

    public function testInsideAWorkspaceTheVhostBecomesTheWorkspaceName(): void
    {
        $this->assertSame(
            'amqp://user:pw@rabbit:5672/prj6',
            $this->resolve($this->processorFor('/var/www/dev-workspaces/prj6/app'))
        );
    }

    public function testOutsideAWorkspaceTheDsnIsUntouched(): void
    {
        // production keeps its own vhost — nothing is derived where there is no workspace
        $this->assertSame(self::DSN, $this->resolve($this->processorFor('/var/www/rc-app-backend/app')));
    }

    public function testTheParameterSwitchesItOff(): void
    {
        $this->assertSame(self::DSN, $this->resolve($this->processorFor('/var/www/dev-workspaces/prj6/app', enabled: false)));
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
            $this->resolve($this->processorFor('/var/www/dev-workspaces/prj6/app', segment: '/checkouts/')),
            'with a different segment configured, the default layout is no longer a workspace'
        );
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
