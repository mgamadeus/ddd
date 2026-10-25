<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Guard;

use Composer\InstalledVersions;

/**
 * Real detection via Composer's runtime API. When `mgamadeus/ddd` is not a dependency at all (this is DDD's own
 * repo/test harness, not a consuming app) it reports a released-looking "0.0.0" — the guard is for consuming apps
 * running unreleased DDD code against a production database, not DDD's own harness, and must stay a no-op here.
 */
final class ComposerInstalledPackageInfo implements InstalledPackageInfo
{
    protected const string PACKAGE_NAME = 'mgamadeus/ddd';

    public function prettyVersion(): string
    {
        if (!$this->isInstalledAsDependency()) {
            return '0.0.0';
        }
        return InstalledVersions::getPrettyVersion(self::PACKAGE_NAME) ?? '0.0.0';
    }

    public function isPathInstall(): bool
    {
        if (!$this->isInstalledAsDependency()) {
            return false;
        }
        $installPath = InstalledVersions::getInstallPath(self::PACKAGE_NAME);
        if ($installPath !== null && is_link(rtrim($installPath, '/'))) {
            return true;
        }
        return $this->distTypeIsPath();
    }

    protected function isInstalledAsDependency(): bool
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE_NAME);
    }

    /**
     * Composer2's runtime API (`InstalledVersions`) carries no `dist.type`; read it from the `installed.json` next
     * to `InstalledVersions.php` itself (`vendor/composer/installed.json`) — best-effort, never fatal if a consuming
     * app's Composer layout differs (an unreadable/unexpected file is simply "not a path install").
     */
    protected function distTypeIsPath(): bool
    {
        $vendorDir = dirname((new \ReflectionClass(InstalledVersions::class))->getFileName(), 2);
        $installedJsonFile = $vendorDir . '/composer/installed.json';
        if (!is_readable($installedJsonFile)) {
            return false;
        }
        $decoded = json_decode((string)file_get_contents($installedJsonFile), true);
        $packages = $decoded['packages'] ?? $decoded ?? [];
        foreach ((array)$packages as $package) {
            if (($package['name'] ?? null) === self::PACKAGE_NAME) {
                return ($package['dist']['type'] ?? null) === 'path';
            }
        }
        return false;
    }
}
