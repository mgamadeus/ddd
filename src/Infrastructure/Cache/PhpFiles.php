<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use Symfony\Component\Cache\Adapter\PhpFilesAdapter;

class PhpFiles extends Cache
{
    protected PhpFilesAdapter $adapter;

    public function getCacheAdapter(): PhpFilesAdapter
    {
        if (!isset($this->adapter)) {
            // Directory default: without CACHE_<GROUP>_DIRECTORY, null lets Symfony place the files under the
            // system temp dir — a missing env var must not fatal a group routed to phpfiles (e.g. a chain tier).
            $this->adapter = new PhpFilesAdapter(
                $this->config['namespace'] ?? '',
                $this->ttl,
                $this->config['directory'] ?? null
            );
        }
        return $this->adapter;
    }
}