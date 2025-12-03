<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class ZipResponseDto extends FileResponseDto
{
    /**
     * @var ResponseHeaderBag
     */
    #[HideProperty]
    public ResponseHeaderBag $headers;

    public function __construct(
        ?string $content = '',
        int $status = 200,
        array $headers = [],
        string $fileName = 'download.zip'
    ) {
        // Pass ZIP content with correct MIME type to base DTO
        parent::__construct($content, $status, $headers, 'application/zip');

        // Force browser to download the file
        $disposition = $this->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $fileName
        );

        // Apply download headers
        $this->headers->set('Content-Disposition', $disposition);
        $this->headers->set('Content-Type', 'application/zip');
    }
}