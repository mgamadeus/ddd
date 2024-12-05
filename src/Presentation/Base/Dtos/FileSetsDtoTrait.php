<?php

namespace DDD\Presentation\Base\Dtos;

use DDD\Domain\Common\Entities\Files\Files;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

trait FileSetsDtoTrait
{
    /** @var object[] The list of uploaded files */
    #[Parameter(in: Parameter::FILES, required: true)]
    public array $fileList;

    /**
     * @return Files
     */
    public function getFiles(): Files
    {
        return new Files($this->fileList);
    }
}
