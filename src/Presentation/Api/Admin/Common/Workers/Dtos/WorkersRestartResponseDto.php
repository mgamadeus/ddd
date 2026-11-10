<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Workers\Dtos;

use DDD\Domain\Common\Entities\Workers\WorkerProcesses;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class WorkersRestartResponseDto extends RestResponseDto
{
    /** @var int Live worker processes that were asked to restart */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $requestedProcessCount = 0;

    /** @var WorkerProcesses The processes that were asked: each stops after its current message */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public WorkerProcesses $workerProcesses;
}
