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
     * creates DateTime from Atom Date Format
     * @param string $stringFormattedDate
     * @param string $dateTimeFormat
     * @return DateTime|bool
     */
    public static function fromString(string $stringFormattedDate, string $dateTimeFormat = self::SIMPLE): DateTime|bool
    {
        $tDate = \DateTime::createFromFormat($dateTimeFormat, $stringFormattedDate);
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
}
