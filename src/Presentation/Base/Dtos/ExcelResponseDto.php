<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use DDD\Domain\Common\Entities\MediaItems\ExcelDocument;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class ExcelResponseDto extends FileResponseDto
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
        string $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ) {
        $headers = array_merge(
            $headers,
            ['Pragma' => 'cache', 'Cache-Control' => 'public, max-age=15552000', 'Content-Type' => $contentType]
        );
        parent::__construct($content, $status, $headers);
    }

    public static function fromExcelDocument(ExcelDocument $excelDocument): ExcelResponseDto
    {
        $excelResponseDto = new ExcelResponseDto(
            $excelDocument->mediaItemContent->getBody(),
            headers: [
                'Content-Disposition' => 'attachment; filename="' . (isset($excelDocument->title) ? $excelDocument->title : 'excel') . '.xlsx"',
                'Content-Length' => strlen($excelDocument->mediaItemContent->getBody())
            ]
        );
        return $excelResponseDto;
    }
}