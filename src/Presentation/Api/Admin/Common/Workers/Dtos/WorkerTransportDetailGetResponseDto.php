<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Workers\Dtos;

use DDD\Domain\Common\Entities\Workers\WorkerTransportDetail;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class WorkerTransportDetailGetResponseDto extends RestResponseDto
{
    /** @var WorkerTransportDetail The transport in the window: sequences, message classes, live processes */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public WorkerTransportDetail $workerTransportDetail;
}
