<?php

declare(strict_types=1);

namespace DDD\Symfony\DependencyInjection\EnvVarProcessors;

use Closure;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * `%env(workspace_vhost:MESSENGER_TRANSPORT_DSN)%` — a broker DSN with its vhost segment replaced by the name of the
 * WORKSPACE this process runs in.
 *
 * Several checkouts of one app share a machine and one broker, and their queue names carry no workspace prefix.
 * Without a vhost per workspace, a message dispatched from one checkout is consumed by whichever checkout's worker
 * gets it first and runs on that checkout's code. The vhost is derived from `kernel.project_dir` — never from an env
 * variable — so FPM, the console and the workers of one checkout land in the same vhost with nothing to configure
 * per workspace, and a synced `.env` cannot break it.
 *
 * Outside a workspace (production, a developer's machine) the DSN is returned UNCHANGED: production keeps its vhost.
 * The parameter {@see self::ENABLED_PARAMETER} is the rollback switch — false means unchanged everywhere.
 *
 * Operational precondition, worth knowing before rolling this out: the vhost must exist on the broker BEFORE a
 * checkout runs with it. A worker that boots without it dies with "530 NOT_ALLOWED - vhost <name> not found".
 * Nothing here probes the broker or falls back to the default vhost — a silent fallback would quietly restore the
 * wrong-code problem this exists to prevent.
 *
 * Runtime processor: Symfony resolves `%env()%` when the container reads the value, so each process derives its own
 * vhost from its own path.
 */
class WorkspaceVhostEnvVarProcessor implements EnvVarProcessorInterface
{
    /** @var string The `%env(...)%` prefix this processor provides */
    public const string PREFIX = 'workspace_vhost';

    /** @var string Container parameter, default true; false returns every DSN unchanged */
    public const string ENABLED_PARAMETER = 'ddd.messenger.workspace_vhost';

    /**
     * @var string Container parameter naming the path segment that marks a workspace checkout. It is the SOURCE OF
     * TRUTH; {@see DDDService::WORKSPACES_PATH_SEGMENT} is only its default, so the two cannot drift apart.
     */
    public const string PATH_SEGMENT_PARAMETER = 'ddd.workspaces_path_segment';

    public function __construct(
        protected string $projectDir,
        protected bool $enabled = true,
        protected ?string $workspacesPathSegment = null
    ) {
    }

    public function getEnv(string $prefix, string $name, Closure $getEnv): string
    {
        $dsn = (string)$getEnv($name);
        if (!$this->enabled) {
            return $dsn;
        }
        return self::replaceVhost(
            $dsn,
            DDDService::workspaceNameFromRootDir($this->projectDir, $this->workspacesPathSegment)
        );
    }

    /**
     * The pure transformation: the vhost of an AMQP DSN is everything after the FIRST slash behind the authority part
     * (`amqp://user:pw@host:5672/%2f` → `%2f`, the URL-encoded `/`). A DSN without a vhost segment gets one appended,
     * a query string (`?heartbeat=…`) is kept, and a null or empty workspace name returns the DSN untouched.
     *
     * @param string $dsn
     * @param string|null $workspaceName
     * @return string
     */
    public static function replaceVhost(string $dsn, ?string $workspaceName): string
    {
        if ($workspaceName === null || $workspaceName === '') {
            return $dsn;
        }
        $queryString = '';
        $queryStart = strpos($dsn, '?');
        if ($queryStart !== false) {
            $queryString = substr($dsn, $queryStart);
            $dsn = substr($dsn, 0, $queryStart);
        }
        $schemeEnd = strpos($dsn, '://');
        $authorityStart = $schemeEnd === false ? 0 : $schemeEnd + 3;
        $vhostSlash = strpos($dsn, '/', $authorityStart);
        $withoutVhost = $vhostSlash === false ? $dsn : substr($dsn, 0, $vhostSlash);
        return $withoutVhost . '/' . $workspaceName . $queryString;
    }

    /** @return array<string, string> */
    public static function getProvidedTypes(): array
    {
        return [self::PREFIX => 'string'];
    }
}
