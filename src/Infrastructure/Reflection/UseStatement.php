<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Reflection;

use DDD\Infrastructure\Traits\Serializer\SerializerTrait;

class UseStatement
{
    use SerializerTrait;

    public string $classOrNamespace = '';
    public string $useAs = '';

    public function __construct(string $classOrNamespace, string $useAs = '')
    {
        $this->classOrNamespace = $classOrNamespace;
        if ($classOrNamespace && !$useAs) {
            preg_match('/(?P<useAs>[0-9a-zA-Z_]+)$/', $classOrNamespace, $matches);
            if (isset($matches['useAs'])) {
                $useAs = $matches['useAs'];
            }
        }
        $this->useAs = $useAs;
    }
}