<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Base\DateTime;

/**
 * default DateTime class for Framework, serializes by default to ATOM format
 * Autodocumenter is aware of this class and interprets it correctly
 */
class DateTime extends \DateTime
{
    public const string SIMPLE = 'Y-m-d H:i:s';

    public const string UBERALL = 'Y-m-d\TH:i:s.uP';

    public const string DATE_RFC3339ZULU = 'Y-m-d\TH:i:sp';

    public const string DATE_RFC3339ZULU_MILLISECONDS = 'Y-m-d\TH:i:s.up';

    public const string DATE_POSTGRES_WITH_TIMEZONE = 'Y-m-d H:i:sO';

    public const string DATE_ARGUS_BUSINESS_LISTINGS = 'Y-m-d\TH:i:s.v\Z';

    public const string DATE_RFC3339_MICROSECONDS_ZULU = 'Y-m-d\TH:i:s.u\Z';

    public const string DATE_RFC3339_ZULU = 'Y-m-d\TH:i:s\Z';

    public const string DATE_RFC3339_MILLISECONDS_OFFSET = 'Y-m-d\TH:i:s.vP';

    public const string UNIX = 'U';

    /**
     * creates Date / DateTime from timestamp
     * @param int $timestamp
     * @return static
     */
    public static function fromTimestamp(int $timestamp): static
    {
        $date = new static();
        $date->setTimestamp($timestamp);
        return $date;
    }

    /**
     * @return Date Returns Date instance from DateTime
     */
    public function getDate(): Date
    {
        $date = new Date();
        $date->setDate(
            (int)$this->format('Y'),  // Jahr
            (int)$this->format('m'),  // Monat
            (int)$this->format('d')   // Tag
        );
        return $date;
    }

    /**
     * creates DateTime from Atom Date Format, supports also multiple formats as array
     * @param string $stringFormattedDate
     * @param string|array $dateTimeFormat
     * @return DateTime|bool
     */
    public static function fromString(
        string $stringFormattedDate,
        string|array $dateTimeFormat = [
            self::SIMPLE,
            self::UBERALL,
            self::DATE_RFC3339ZULU,
            self::DATE_RFC3339ZULU_MILLISECONDS,
            self::DATE_POSTGRES_WITH_TIMEZONE,
            self::DATE_ARGUS_BUSINESS_LISTINGS,
            self::DATE_RFC3339_MICROSECONDS_ZULU,
            self::DATE_RFC3339_ZULU,
            self::DATE_RFC3339_MILLISECONDS_OFFSET,
        ]
    ): DateTime|bool {
        if (is_string($dateTimeFormat)) {
            $tDate = \DateTime::createFromFormat($dateTimeFormat, $stringFormattedDate);
        } elseif (is_array($dateTimeFormat)) {
            $tDate = null;
            foreach ($dateTimeFormat as $actFormat) {
                $tDate = \DateTime::createFromFormat($actFormat, $stringFormattedDate);
                if ($tDate) {
                    break;
                }
            }
        }
        // try to interpret also atom date format
        if (!$tDate) {
            $tDate = \DateTime::createFromFormat(self::ATOM, $stringFormattedDate);
        }
        if ($tDate) {
            $date = new DateTime();
            $date->setTimestamp($tDate->getTimestamp());
            return $date;
        }

        return false;
    }

    public function jsonSerialize(): string
    {
        return $this->format(static::SIMPLE);
    }

    public function __toString(): string
    {
        return $this->format(static::SIMPLE);
    }

    /**
     * Returns the number of days difference between this Date and otherDate
     * @param Date $otherDate
     * @return int
     */
    public function getNumberOfDaysDiff(Date $otherDate): int
    {
        $interval = $this->diff($otherDate);
        return abs((int)$interval->format('%a'));
    }

    public function setTimeZoneWithoutChangingTime(\DateTimeZone $newTimeZone): void {
        // Check if the timezone is the same, and return if no change is needed
        if ($newTimeZone->getName() === $this->getTimezone()->getName()) {
            return;
        }

        // Clone the current DateTime to keep original time values intact
        $originalDateTime = clone $this;

        // Change the timezone without altering the time representation
        $this->setTimezone($newTimeZone);

        // Set the date and time parts including microseconds to match the original
        $this->setDate(
            (int) $originalDateTime->format('Y'),
            (int) $originalDateTime->format('m'),
            (int) $originalDateTime->format('d')
        );

        $this->setTime(
            (int) $originalDateTime->format('H'),
            (int) $originalDateTime->format('i'),
            (int) $originalDateTime->format('s'),
            (int) $originalDateTime->format('u') // microseconds
        );
    }
}