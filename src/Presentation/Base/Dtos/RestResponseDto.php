<?php

declare(strict_types=1);

namespace DDD\Presentation\Base\Dtos;

use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Traits\Serializer\SerializerTrait;
use DDD\Infrastructure\Traits\ValidatorTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class RestResponseDto extends JsonResponse
{
    use SerializerTrait, ValidatorTrait;

    /**
     * @var ResponseHeaderBag
     */
    #[HideProperty]
    public ResponseHeaderBag $headers;

    public function __construct(mixed $data = null, int $status = 200, array $headers = [], bool $json = false)
    {
        parent::__construct($data, $status, $headers, $json);
        $this->headers->set('charset', 'utf-8');
    }


    /**
     * Gets the current response content.
     */
    public function getContent(): string|false
    {
        if (!($this->content && $this->content != '{}')) {
            $this->data = $this->toJSON();
            $this->update();
        }
        return $this->content;
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