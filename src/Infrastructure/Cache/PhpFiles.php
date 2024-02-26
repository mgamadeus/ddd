<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Cache;

use Symfony\Component\Cache\Adapter\PhpFilesAdapter;

class PhpFiles extends Cache
{
    private PhpFilesAdapter $adapter;

    public function getCacheAdapter(): PhpFilesAdapter
    {
        if (!isset($this->adapter)) {
            $this->adapter = new PhpFilesAdapter(
                $this->config['namespace'] ?? '',
                $this->ttl,
                    $this->config['directory']
            );
        }
        return $this->adapter;
    }
}