<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Charts;

use DateTimeInterface;
use DateTimeZone;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use Exception;

/**
 * A time window [start, end] — of a chart, a report, a trend. Either end may be open (null).
 */
class TimeInterval extends ValueObject
{
    public ?DateTime $startDate = null;

    public ?DateTime $endDate = null;

    public function __construct(?DateTime $startDate = null, ?DateTime $endDate = null)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        parent::__construct();
    }

    public function setStartTime(?DateTime $startDate): void
    {
        $this->startDate = $startDate;
    }

    public function getStartTime(): ?DateTime
    {
        return $this->startDate;
    }

    public function setEndTime(?DateTime $endDate): void
    {
        $this->endDate = $endDate;
    }

    public function getEndTime(): ?DateTime
    {
        return $this->endDate;
    }

    /** Length in seconds; null when an end is open */
    public function getDurationSeconds(): ?int
    {
        return $this->startDate !== null && $this->endDate !== null
            ? $this->endDate->getTimestamp() - $this->startDate->getTimestamp()
            : null;
    }

    /**
     * A date or date-time string as UTC DateTime (`2026-10-28`, `2026/10/28 10:00:00`, ISO 8601); a date alone is
     * midnight. Null for an empty or unparseable string.
     */
    public function stringToDateTime(?string $stringDate): ?DateTime
    {
        if (!$stringDate) {
            return null;
        }
        try {
            $stringDate = str_replace('/', '-', trim($stringDate));
            $hasTimeInfo = preg_match('/\d{2}:\d{2}:\d{2}/', $stringDate) || str_contains($stringDate, 'T');
            if (!$hasTimeInfo) {
                $stringDate .= ' 00:00:00';
            }
            return new DateTime($stringDate, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    public function uniqueKey(): string
    {
        $key = '';
        if ($this->startDate !== null) {
            $key .= $this->startDate->format(DateTimeInterface::ATOM);
        }
        if ($this->endDate !== null) {
            $key .= '-' . $this->endDate->format(DateTimeInterface::ATOM);
        }
        return self::uniqueKeyStatic($key);
    }
}
