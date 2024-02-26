<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Controller\Filters;

use Attribute;
use DDD\Domain\Base\Entities\Attributes\BaseAttributeTrait;

#[Attribute]
class After
{
    use BaseAttributeTrait;
}