<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Workers\Dtos;

use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * One transport and the window of its charts.
 */
class WorkerTransportGetRequestDto extends WorkersWindowRequestDto
{
    /** @var string The Messenger transport name */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $transport;
}
