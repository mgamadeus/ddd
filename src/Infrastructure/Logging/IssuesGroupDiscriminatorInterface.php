<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging;

/**
 * A throwable that names what distinguishes its root cause beyond class and throw site — for a throwable created at
 * one place for many causes (one cron executor reporting the failures of many crons). The value joins
 * `error.group_hash`; keep it free of volatile parts (ids of the moment, timestamps, counts).
 */
interface IssuesGroupDiscriminatorInterface
{
    public function getIssuesGroupDiscriminator(): string;
}
