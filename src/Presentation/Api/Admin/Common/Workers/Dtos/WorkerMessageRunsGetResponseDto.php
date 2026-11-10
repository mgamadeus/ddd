<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Workers\Dtos;

use DDD\Domain\Common\Entities\Workers\WorkerMessageRuns;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class WorkerMessageRunsGetResponseDto extends RestResponseDto
{
    /** @var WorkerMessageRuns The message runs of the transport */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public WorkerMessageRuns $workerMessageRuns;
}
