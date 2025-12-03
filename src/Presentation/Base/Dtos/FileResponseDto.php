<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use DDD\Domain\Common\Entities\MediaItems\PDFDocument;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class FileResponseDto extends Response
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
        string $contentType = 'application/octet-stream'
    ) {
        // put content type into header
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = $contentType;
        }

        parent::__construct($content, $status, $headers);
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