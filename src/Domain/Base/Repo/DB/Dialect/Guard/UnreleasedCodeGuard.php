<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Guard;

/**
 * §6 build step 2: when the installed `mgamadeus/ddd` is `dev-*` or a path install, refuse a connection whose
 * host or port matches the production allowlist — a developer workspace or CI job running a feature branch must
 * never reach a production database. The allowlist is configuration, not code: `DB_PRODUCTION_HOSTS` /
 * `DB_PRODUCTION_PORTS` (comma-separated) in the app's environment — a public framework package names no
 * deployment's hosts. A tagged release (`X.Y.Z` or `X.Y.Z-RC<n>`) always boots: the guard only exists for code that
 * was never reviewed/released.
 *
 * An empty host+port list fails CLOSED: an unreleased install that cannot determine what "production" means refuses
 * every connection rather than silently allowing one — set both variables in the workspace's `.env.local`.
 */
class UnreleasedCodeGuard
{
    /**
     * @param array<string, mixed> $connectionParams Resolved DBAL params (post `$dialect->connectionParams()`)
     * @param string[]|null $productionHosts Test-only override; null resolves `DB_PRODUCTION_HOSTS`
     * @param int[]|null $productionPorts Test-only override; null resolves `DB_PRODUCTION_PORTS`
     */
    public static function assertAllowed(
        array $connectionParams,
        ?InstalledPackageInfo $packageInfo = null,
        ?array $productionHosts = null,
        ?array $productionPorts = null
    ): void {
        $packageInfo ??= new ComposerInstalledPackageInfo();
        if (self::isReleased($packageInfo)) {
            return;
        }

        $hosts = $productionHosts ?? self::productionHosts();
        $ports = $productionPorts ?? self::productionPorts();
        if ($hosts === [] && $ports === []) {
            throw new UnreleasedCodeProductionGuardException(
                'Production host/port allowlist is empty: an unreleased mgamadeus/ddd install (dev-* or path) '
                . 'refuses every DB connection until DB_PRODUCTION_HOSTS/DB_PRODUCTION_PORTS are configured in its '
                . 'environment (fails closed).'
            );
        }

        $host = (string)($connectionParams['host'] ?? '');
        $port = isset($connectionParams['port']) ? (int)$connectionParams['port'] : null;
        $matchesHost = $host !== '' && in_array($host, $hosts, true);
        $matchesPort = $port !== null && in_array($port, $ports, true);
        if ($matchesHost || $matchesPort) {
            throw new UnreleasedCodeProductionGuardException(sprintf(
                'Refusing to connect an unreleased mgamadeus/ddd install (%s) to %s%s: it matches the production '
                . 'host/port allowlist.',
                $packageInfo->isPathInstall() ? 'path install' : ('version ' . $packageInfo->prettyVersion()),
                $host !== '' ? $host : '(no host)',
                $port !== null ? ":$port" : ''
            ));
        }
    }

    protected static function isReleased(InstalledPackageInfo $packageInfo): bool
    {
        if ($packageInfo->isPathInstall()) {
            return false;
        }
        $version = ltrim($packageInfo->prettyVersion(), 'vV');
        return (bool)preg_match('/^\d+\.\d+\.\d+(-RC\d+)?$/i', $version);
    }

    /** @return string[] */
    protected static function productionHosts(): array
    {
        return array_values(array_unique(self::envList('DB_PRODUCTION_HOSTS')));
    }

    /** @return int[] */
    protected static function productionPorts(): array
    {
        return array_values(array_unique(array_map('intval', self::envList('DB_PRODUCTION_PORTS'))));
    }

    /** @return string[] */
    protected static function envList(string $envName): array
    {
        // $_ENV first: Symfony's Dotenv fills it (and $_SERVER) without putenv(), so getenv() alone misses .env.local
        $raw = $_ENV[$envName] ?? $_SERVER[$envName] ?? getenv($envName);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));
    }
}
