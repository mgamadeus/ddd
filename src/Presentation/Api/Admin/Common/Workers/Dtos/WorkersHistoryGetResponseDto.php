<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Workers\Dtos;

use DDD\Domain\Common\Entities\Workers\WorkersHistory;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class WorkersHistoryGetResponseDto extends RestResponseDto
{
    /** @var WorkersHistory Per transport the sequences processes, processStarts, waiting, inFlight, handled, failed */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public WorkersHistory $workersHistory;
}
