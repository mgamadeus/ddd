<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Charts;

use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A named time series of {@see DateTimeValue}s — sub-day or day buckets (a chart's line). For a bucketed series
 * {@see self::$stepSeconds} is the bucket size (a chart labels its axis with it) and {@see self::$interval} the window;
 * {@see self::fromValuesByTimestamp()} builds it step-aligned (UTC) with one point per bucket: an empty bucket is 0,
 * or null (a gap) for a measure that has no value without a reading.
 * {@see DateValueSequence} stays the day-only series.
 *
 * @property DateTimeValue[] $elements;
 * @method DateTimeValue[] getElements()
 * @method DateTimeValue|null first()
 */
class DateTimeValueSequence extends ObjectSet
{
    public string $name;

    /** Bucket size in seconds; null = the points are not bucketed */
    public ?int $stepSeconds = null;

    /** The window the series covers */
    public ?TimeInterval $interval = null;

    public function __construct(?string $name = null, ?int $stepSeconds = null)
    {
        if ($name !== null) {
            $this->name = $name;
        }
        $this->stepSeconds = $stepSeconds;
        parent::__construct();
    }

    /** The start of the bucket of `$stepSeconds` (UTC epoch-aligned) holding the timestamp */
    public static function alignToStep(int $timestamp, int $stepSeconds): int
    {
        return intdiv($timestamp, $stepSeconds) * $stepSeconds;
    }

    /**
     * The gap-free series of the window: one point per bucket from the bucket holding `from` to the one holding `to`;
     * values at timestamps inside a bucket are added to it (an internal helper: the map comes straight from a query
     * result and is wrapped here).
     *
     * @param array<int, int|float> $valuesByTimestamp Unix timestamp → value
     * @param bool $emptyBucketsAreGaps A bucket without a value is null (nothing was measured) instead of 0
     */
    public static function fromValuesByTimestamp(
        string $name,
        DateTime $from,
        DateTime $to,
        int $stepSeconds,
        array $valuesByTimestamp,
        bool $emptyBucketsAreGaps = false
    ): static {
        $dateTimeValueSequence = new static($name, $stepSeconds);
        $dateTimeValueSequence->interval = new TimeInterval($from, $to);
        $valuesByBucketStart = [];
        foreach ($valuesByTimestamp as $timestamp => $value) {
            $bucketStart = self::alignToStep((int)$timestamp, $stepSeconds);
            $valuesByBucketStart[$bucketStart] = ($valuesByBucketStart[$bucketStart] ?? 0) + $value;
        }
        $lastBucketStart = self::alignToStep($to->getTimestamp(), $stepSeconds);
        for ($bucketStart = self::alignToStep($from->getTimestamp(), $stepSeconds); $bucketStart <= $lastBucketStart; $bucketStart += $stepSeconds) {
            $value = $valuesByBucketStart[$bucketStart] ?? ($emptyBucketsAreGaps ? null : 0);
            $dateTimeValue = new DateTimeValue(DateTime::fromTimestamp($bucketStart), $value === null ? null : (float)$value);
            $dateTimeValueSequence->add($dateTimeValue);
        }
        return $dateTimeValueSequence;
    }

    /**
     * The values per timestamp (to cache a series or to add series up); a gap (null) is left out.
     *
     * @return array<int, float>
     */
    public function getValuesByTimestamp(): array
    {
        $valuesByTimestamp = [];
        foreach ($this->getElements() as $dateTimeValue) {
            if ($dateTimeValue->y !== null) {
                $valuesByTimestamp[$dateTimeValue->x->getTimestamp()] = (float)$dateTimeValue->y;
            }
        }
        return $valuesByTimestamp;
    }

    /** The sum of the values */
    public function getTotal(): float
    {
        return array_sum(array_map(static fn(DateTimeValue $dateTimeValue): float => (float)$dateTimeValue->y, $this->getElements()));
    }

    public function orderByDateTime(): static
    {
        $elements = $this->getElements();
        usort($elements, static fn(DateTimeValue $left, DateTimeValue $right): int => $left->x->getTimestamp() <=> $right->x->getTimestamp());
        $dateTimeValueSequence = new static($this->name ?? null, $this->stepSeconds);
        $dateTimeValueSequence->interval = $this->interval;
        foreach ($elements as $element) {
            $dateTimeValueSequence->add($element);
        }
        return $dateTimeValueSequence;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name ?? '');
    }
}
