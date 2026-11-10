<?php

declare(strict_types=1);

namespace DDD\Tests\Symfony\Messenger\Middleware\Fixtures;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/** A message whose dispatch origin a test reads */
class DispatchOriginProbeMessage extends AppMessage
{
}
