<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class ImageResponseDto extends FileResponseDto
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
        string $contentType = 'image/jpeg'
    ) {
        $headers = ['Pragma' => 'cache', 'Cache-Control' => 'public, max-age=15552000', 'Content-Type' => $contentType];
        parent::__construct($content, $status, $headers);
    }
}