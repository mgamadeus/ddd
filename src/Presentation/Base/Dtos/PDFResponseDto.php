<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use DDD\Domain\Common\Entities\MediaItems\PDFDocument;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class PDFResponseDto extends Response
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
        string $contentType = 'application/pdf'
    ) {
        $headers = array_merge(
            $headers,
            ['Pragma' => 'cache', 'Cache-Control' => 'public, max-age=15552000', 'Content-Type' => $contentType]
        );
        parent::__construct($content, $status, $headers);
    }

    public static function fromPDFDocument(PDFDocument $pdfDocument): PDFResponseDto
    {
        $pdfResponseDto = new PDFResponseDto(
            $pdfDocument->mediaItemContent->getBody(),
            headers: [
                'Content-Disposition' => 'attachment; filename="' . (isset($pdfDocument->title) ? $pdfDocument->title : 'pdf') . '.pdf"',
                'Content-Length' => strlen($pdfDocument->mediaItemContent->getBody())
            ]
        );
        return $pdfResponseDto;
    }


    public function setContentType(string $mimeType): void
    {
        $this->headers->set('Content-Type', $mimeType);
    }

    /**
     * Sends content for the current web response.
     *
     * @return $this
     */
    public function sendContent(): static
    {
        echo $this->getContent();

        return $this;
    }
}