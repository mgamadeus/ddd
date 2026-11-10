<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Workers\Dtos;

use DDD\Domain\Common\Entities\Workers\WorkersOverview;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

class WorkersOverviewGetResponseDto extends RestResponseDto
{
    /** @var WorkersOverview Every transport with definition, queue, processes and state, the totals and the loops */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public WorkersOverview $workersOverview;
}
