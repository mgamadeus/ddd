<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\OpenApi\Pathes;

use DDD\Infrastructure\Exceptions\Exception;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Presentation\Base\Dtos\FileResponseDto;
use DDD\Presentation\Base\Dtos\HtmlResponseDto;
use DDD\Presentation\Base\Dtos\ImageResponseDto;
use DDD\Presentation\Base\Dtos\PDFResponseDto;
use DDD\Presentation\Base\Dtos\RedirectResponseDto;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\Dtos\ZipResponseDto;

class PathResponse
{
    use SerializerTrait;

    public string $description = '';

    /** @var RequestBodySchema[][] */
    public ?array $content = [];

    public function __construct(ReflectionClass &$responseDtoReflectionClass)
    {
        $docComment = $responseDtoReflectionClass->getDocCommentInstance();
        $description = $docComment->getDescription();
        if ($description) {
            $this->description = $description;
        }
        if (is_a($responseDtoReflectionClass->getName(), RestResponseDto::class, true)) {
            $this->content['application/json'] = new ResponseBodySchema($responseDtoReflectionClass);
        } elseif (is_a($responseDtoReflectionClass->getName(), RedirectResponseDto::class, true)) {
            $htmlResponseDtoReflectionClass = ReflectionClass::instance(HtmlResponseDto::class);
            $this->content['text/html'] = new ResponseBodySchema($htmlResponseDtoReflectionClass);
            $this->description = 'Redirect Response';
        } elseif (is_a($responseDtoReflectionClass->getName(), Exception::class, true)) {
            $this->content['application/json'] = new ResponseBodySchema($responseDtoReflectionClass);
        } elseif (is_a($responseDtoReflectionClass->getName(), ImageResponseDto::class, true)) {
            $this->content['image/*'] = new ResponseBodySchema($responseDtoReflectionClass);
        } elseif (is_a($responseDtoReflectionClass->getName(), PDFResponseDto::class, true)) {
            $this->content['application/pdf'] = new ResponseBodySchema($responseDtoReflectionClass);
        } elseif (is_a($responseDtoReflectionClass->getName(), ZipResponseDto::class, true)) {
            $this->content['application/zip'] = new ResponseBodySchema($responseDtoReflectionClass);
        } elseif (is_a($responseDtoReflectionClass->getName(), FileResponseDto::class, true)) {
            $this->content['application/octet-stream'] = new ResponseBodySchema($responseDtoReflectionClass);
        } elseif (is_a($responseDtoReflectionClass->getName(), HtmlResponseDto::class, true)) {
            $this->content['text/html'] = new ResponseBodySchema($responseDtoReflectionClass);
        }
    }
}