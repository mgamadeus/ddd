<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\CompilerPasses\Fixtures\ModuleSource;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Plumbing built with `new` in a public module namespace: its constructor argument cannot be autowired */
#[Exclude]
class ExcludedModulePlumbing
{
    public function __construct(protected OutputInterface $output)
    {
    }
}
