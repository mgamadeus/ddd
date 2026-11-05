<?php

declare(strict_types=1);

namespace DDD\Symfony\DependencyInjection\EnvVarProcessors;

use Closure;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\Connection;

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
 * Inside a workspace the broker is probed once per process: when the workspace vhost does not exist ("530 NOT_ALLOWED
 * - vhost <name> not found"), the DSN from `.env` is returned unchanged, so the checkout falls back to its default
 * vhost. Any other failure — broker down, bad credentials, access to an existing vhost refused — keeps the workspace
 * DSN so the real error surfaces. The probe opens the connection through Symfony's own
 * {@see Connection::fromDsn()}, so credentials, TLS and every DSN option are exactly those the transport will use.
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

    /** @var array<string, bool> Per-process memo of probed workspace DSNs → whether their vhost exists */
    protected static array $vhostExists = [];

    /**
     * The #[Autowire] attributes are LOAD-BEARING, not decoration. Applications auto-register the framework's own
     * sources (`resource: vendor/mgamadeus/ddd/src/*` with autowire: true), and such a definition REPLACES the one
     * {@see \DDD\DDDBundle::build()} registers. Without the attributes the container then cannot compile at all —
     * "Cannot autowire service …WorkspaceVhostEnvVarProcessor: argument $projectDir is type-hinted string". With
     * them, either definition resolves, so an app needs no exclude and no knowledge of this class.
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%')] protected string $projectDir,
        #[Autowire('%' . self::ENABLED_PARAMETER . '%')] protected bool $enabled = true,
        #[Autowire('%' . self::PATH_SEGMENT_PARAMETER . '%')] protected ?string $workspacesPathSegment = null
    ) {
    }

    /**
     * @param string $prefix
     * @param string $name
     * @param Closure $getEnv
     * @return string
     */
    public function getEnv(string $prefix, string $name, Closure $getEnv): string
    {
        $dsn = (string)$getEnv($name);
        if (!$this->enabled) {
            return $dsn;
        }
        $workspaceDsn = self::replaceVhost(
            $dsn,
            DDDService::workspaceNameFromRootDir($this->projectDir, $this->workspacesPathSegment)
        );
        if ($workspaceDsn === $dsn) {
            return $dsn;
        }
        return $this->vhostExists($workspaceDsn) ? $workspaceDsn : $dsn;
    }

    /**
     * Probes the broker once per process and DSN through Symfony's AMQP {@see Connection}: the DSN is parsed and the
     * connection opened exactly as the transport does it, nothing is declared. Returns false only for "vhost <name>
     * not found"; a refused access to an existing vhost is also a 530 NOT_ALLOWED but must surface, so it returns
     * true. Without ext-amqp or for a non-AMQP scheme the probe is skipped and the vhost is assumed to exist; an
     * invalid DSN or TLS configuration fails the transport itself the same way, so it is left to surface there.
     *
     * @param string $dsn
     * @return bool
     */
    protected function vhostExists(string $dsn): bool
    {
        if (isset(self::$vhostExists[$dsn])) {
            return self::$vhostExists[$dsn];
        }
        if (!class_exists(\AMQPConnection::class)
            || !in_array(parse_url($dsn, PHP_URL_SCHEME), ['amqp', 'amqps'], true)) {
            return self::$vhostExists[$dsn] = true;
        }
        try {
            $channel = Connection::fromDsn($dsn, ['connect_timeout' => 2])->channel();
            $channel->getConnection()->disconnect();
            return self::$vhostExists[$dsn] = true;
        } catch (\AMQPException $e) {
            $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();
            return self::$vhostExists[$dsn] = !self::isVhostNotFound($message);
        } catch (\Exception) {
            return self::$vhostExists[$dsn] = true;
        }
    }

    /**
     * Whether a broker connection error says the vhost does not exist. RabbitMQ answers both a missing vhost
     * ("NOT_ALLOWED - vhost adk not found") and a refused access to an existing one ("NOT_ALLOWED - access to vhost
     * 'adk' refused for user 'x'") with 530 NOT_ALLOWED; only the first justifies a fallback.
     *
     * @param string $message
     * @return bool
     */
    public static function isVhostNotFound(string $message): bool
    {
        $message = strtolower($message);
        return str_contains($message, 'vhost') && str_contains($message, 'not found');
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
