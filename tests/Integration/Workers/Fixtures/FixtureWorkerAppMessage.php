<?php

declare(strict_types=1);

namespace DDD\Tests\Integration\Workers\Fixtures;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

/** A message as applications dispatch them: a value object, so its class name names a value object class */
class FixtureWorkerAppMessage extends AppMessage
{
}
