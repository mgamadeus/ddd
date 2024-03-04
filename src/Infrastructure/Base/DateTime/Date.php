<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Base\DateTime;

use DateTimeZone;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Infrastructure\Services\DDDService;

/**
 * default DateTime class for Framework, serializes by default to 'Y-m-d' format
 * Autodocumenter is aware of this class and interprets it correctly
 */
class Date extends DateTime
{
    public const DATE = 'Y-m-d';

    public const DATE_GERMAN = 'd.m.Y';
    public const DATE_MONTH_YEAR = 'm-Y';
    public const YEAR_MONTH_DATE = 'Ymd';

    private $toStringCache = null;

    /**
     * Creates a Date object from a string formatted date.
     * The resulting Date object will have a time component set to 00:00:00 UTC.
     *
     * @param string $stringFormattedDate The date string to convert.
     * @param string $format The format of the input date string. Defaults to self::DATE.
     * @return Date|bool Date object on success, or false on failure.
     */
    public static function fromString(string $stringFormattedDate, string $format = self::DATE): Date|bool
    {
        if (isset(StaticRegistry::$dateFromStringCache[$stringFormattedDate])) {
            return StaticRegistry::$dateFromStringCache[$stringFormattedDate];
        }

        $tDate = \DateTime::createFromFormat($format, $stringFormattedDate);
        if ($tDate === false) {
            return false;
        }

        // Set time to 00:00:00 and timezone to UTC
        $tDate->setTime(0, 0, 0);
        $tDate->setTimezone(new DateTimeZone('UTC'));

        $date = new Date();
        $date->setTimestamp($tDate->getTimestamp());

        // Store in cache unless memory usage is high
        if (!DDDService::instance()->isMemoryUsageHigh()) {
            StaticRegistry::$dateFromStringCache[$stringFormattedDate] = $date;
        }

        return $date;
    }

    /**
     * Creates Date by passing year, month and day of month
     * @param int $year
     * @param int $month
     * @param int $dayOfMonth
     * @return Date|bool
     */
    public static function fromYearMonthDay(int $year, int $month, int $dayOfMonth): Date|bool
    {
        $dateString = $year . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '-' . str_pad(
                (string)$dayOfMonth,
                2,
                '0',
                STR_PAD_LEFT
            );
        return self::fromString($dateString);
    }

    public function jsonSerialize(): string
    {
        return $this->format(static::DATE);
    }

    public function __toString(): string
    {
        if ($this->toStringCache) {
            return $this->toStringCache;
        }
        $return = $this->format(static::DATE);
        $this->toStringCache = $return;
        return $return;
    }

    public function modify(string $modifier): Date|false
    {
        $this->toStringCache = null;
        return parent::modify($modifier);
    }

    /**
     * sets date bsed on Y-m-d formatted string
     * @param string $stringFormattedDate
     * @return static
     */
    public function setFromString(string $stringFormattedDate)
    {
        $tDate = \DateTime::createFromFormat(self::DATE, $stringFormattedDate);
        $this->setTimestamp($tDate->getTimestamp());
        return $this;
    }
}