<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Workers\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class WorkersRestartRequestDto extends RequestDto
{
    /** @var string|null Only the worker processes of this transport; without it those of all transports */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $transport = null;
}
