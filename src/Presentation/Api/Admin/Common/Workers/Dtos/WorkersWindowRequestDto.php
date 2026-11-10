<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Common\Workers\Dtos;

use DDD\Domain\Common\Entities\Charts\TimeInterval;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The time window and step of a workers chart request.
 */
class WorkersWindowRequestDto extends RequestDto
{
    /** @var string|null Start of the window, UTC (`2026-01-31 10:00:00` or ISO 8601); default one hour before `to` */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $from = null;

    /** @var string|null End of the window, UTC (`2026-01-31 11:00:00` or ISO 8601); default now */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?string $to = null;

    /** @var int|null Bucket size of the sequences in seconds: 60, 300, 900 or 3600; default chosen by the window's length */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $stepSeconds = null;

    /**
     * @throws BadRequestException When `from` is not a date-time
     */
    public function getFromDateTime(): ?DateTime
    {
        return $this->parseDateTime('from', $this->from);
    }

    /**
     * @throws BadRequestException When `to` is not a date-time
     */
    public function getToDateTime(): ?DateTime
    {
        return $this->parseDateTime('to', $this->to);
    }

    /**
     * @throws BadRequestException
     */
    protected function parseDateTime(string $parameterName, ?string $value): ?DateTime
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $dateTime = (new TimeInterval())->stringToDateTime($value);
        if ($dateTime === null) {
            throw new BadRequestException(sprintf('"%s" is not a date-time: %s', $parameterName, $value));
        }
        return $dateTime;
    }
}
