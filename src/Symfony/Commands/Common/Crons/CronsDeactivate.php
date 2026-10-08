<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Crons;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Switches one or several Crons OFF by name; schedule and command stay untouched. See {@see CronsSetActiveCommand}.
 */
#[AsCommand(
    name: 'app:crons:deactivate',
    description: 'Deactivates one or several Crons by name (schedule and command untouched) — --dryRun supported'
)]
class CronsDeactivate extends CronsSetActiveCommand
{
    protected const bool TARGET_ACTIVE = false;
}
