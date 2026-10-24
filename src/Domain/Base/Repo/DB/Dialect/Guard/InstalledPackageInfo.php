<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\DB\Dialect\Guard;

/**
 * What {@see UnreleasedCodeGuard} needs to know about how `mgamadeus/ddd` got into the consuming app's `vendor/` —
 * an interface so the guard's fixtures can inject a fake without faking Composer's runtime metadata.
 */
interface InstalledPackageInfo
{
    /** e.g. "2.70.9", "v2.70.9", "2.80.0-RC1", "dev-postgres", "dev-main". */
    public function prettyVersion(): string;

    /** True for a `path` repository install (composer.json `"type": "path"`), symlinked or copied. */
    public function isPathInstall(): bool;
}
