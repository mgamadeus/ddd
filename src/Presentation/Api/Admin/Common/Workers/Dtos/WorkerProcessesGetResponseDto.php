<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Workers\Dtos;

use DDD\Domain\Common\Entities\Workers\WorkerProcesses;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class WorkerProcessesGetResponseDto extends RestResponseDto
{
    /** @var WorkerProcesses The worker processes of the transport, each with its display state */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public WorkerProcesses $workerProcesses;
}
