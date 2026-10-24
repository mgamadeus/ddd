<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Guard;

/**
 * §6 build step 2: when the installed `mgamadeus/ddd` is `dev-*` or a path install, refuse a connection whose
 * host or port matches the production allowlist — a developer workspace or CI job running a feature branch must
 * never reach a production database. DDD ships a non-empty default list; a consuming app may only EXTEND it via
 * `DB_PRODUCTION_HOSTS` / `DB_PRODUCTION_PORTS` (comma-separated), never shrink it — there is no supported way to
 * remove a default entry. A tagged release (`X.Y.Z` or `X.Y.Z-RC<n>`) always boots: the guard only exists for code
 * that was never reviewed/released.
 *
 * An explicitly empty host+port list (the `$productionHosts`/`$productionPorts` fixture override, never reachable
 * through normal configuration since the shipped defaults are always present) fails CLOSED: an unreleased install
 * that cannot determine what "production" means refuses every connection rather than silently allowing one.
 */
final class UnreleasedCodeGuard
{
    /** @var string[] */
    public const array DEFAULT_PRODUCTION_HOSTS = ['88.198.125.87', '88.99.252.236', '144.76.115.183', 'pg-main'];

    /** @var int[] */
    public const array DEFAULT_PRODUCTION_PORTS = [6033, 13306];

    /**
     * @param array<string, mixed> $connectionParams Resolved DBAL params (post `$dialect->connectionParams()`)
     * @param string[]|null $productionHosts Test-only override; null resolves DDD defaults + `DB_PRODUCTION_HOSTS`
     * @param int[]|null $productionPorts Test-only override; null resolves DDD defaults + `DB_PRODUCTION_PORTS`
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
                . 'refuses every DB connection until DB_PRODUCTION_HOSTS/DB_PRODUCTION_PORTS are configured '
                . '(fails closed; the shipped defaults make this unreachable outside a test).'
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

    private static function isReleased(InstalledPackageInfo $packageInfo): bool
    {
        if ($packageInfo->isPathInstall()) {
            return false;
        }
        $version = ltrim($packageInfo->prettyVersion(), 'vV');
        return (bool)preg_match('/^\d+\.\d+\.\d+(-RC\d+)?$/i', $version);
    }

    /** @return string[] */
    private static function productionHosts(): array
    {
        return array_values(array_unique([...self::DEFAULT_PRODUCTION_HOSTS, ...self::envList('DB_PRODUCTION_HOSTS')]));
    }

    /** @return int[] */
    private static function productionPorts(): array
    {
        $extra = array_map('intval', self::envList('DB_PRODUCTION_PORTS'));
        return array_values(array_unique([...self::DEFAULT_PRODUCTION_PORTS, ...$extra]));
    }

    /** @return string[] */
    private static function envList(string $envName): array
    {
        $raw = getenv($envName);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== ''));
    }
}
