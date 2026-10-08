<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Common\Crons;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Switches one or several Crons ON by name; a Cron paused across its slot is scheduled at its NEXT slot. See
 * {@see CronsSetActiveCommand}.
 */
#[AsCommand(
    name: 'app:crons:activate',
    description: 'Activates one or several Crons by name and schedules them at their next slot — --dryRun supported'
)]
class CronsActivate extends CronsSetActiveCommand
{
    protected const bool TARGET_ACTIVE = true;
}
