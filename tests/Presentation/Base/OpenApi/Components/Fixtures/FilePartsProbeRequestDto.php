<?php

declare(strict_types=1);

namespace DDD\Tests\Presentation\Base\OpenApi\Components\Fixtures;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FilePartsProbeRequestDto extends RequestDto
{
    /** @var string|null The caption sent in the JSON envelope */
    #[Parameter(in: Parameter::BODY, required: false)]
    public ?string $caption = null;

    /** @var UploadedFile[]|null Attachments sent as multipart parts */
    #[Parameter(in: Parameter::FILES, required: false)]
    public ?array $attachmentFiles = null;

    /** @var object[] Files of an upload-only request */
    #[Parameter(in: Parameter::FILES, required: true)]
    public array $fileList;
}
