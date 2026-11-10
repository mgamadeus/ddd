<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Workers\Dtos;

use DDD\Domain\Common\Entities\Workers\WorkerProcesses;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptions;
use DDD\Presentation\Base\QueryOptions\DtoQueryOptionsTrait;

#[DtoQueryOptions(baseEntity: WorkerProcesses::class)]
class WorkerProcessesGetRequestDto extends RequestDto
{
    use DtoQueryOptionsTrait;

    /** @var string The Messenger transport name */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $transport;
}
