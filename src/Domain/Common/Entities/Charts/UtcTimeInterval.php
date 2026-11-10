<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Charts;

use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * A {@see TimeInterval} that serialises both ends as ISO 8601 in UTC (`2026-10-28T10:00:00Z`) — the format of the
 * points of a {@see DateTimeValueSequence}, so a client reads the window and its points the same way.
 */
class UtcTimeInterval extends TimeInterval
{
    public function toObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true,
        int $flags = 0
    ): mixed {
        $serialized = parent::toObject($cached, $returnUniqueKeyInsteadOfContent, $path, $ignoreHideAttributes, $ignoreNullValues, $forPersistence, $flags);
        if ($returnUniqueKeyInsteadOfContent || !is_array($serialized)) {
            return $serialized;
        }
        foreach (['startDate' => $this->startDate, 'endDate' => $this->endDate] as $propertyName => $dateTime) {
            if ($dateTime !== null) {
                $serialized[$propertyName] = gmdate(DateTime::DATE_RFC3339_ZULU, $dateTime->getTimestamp());
            }
        }
        return $serialized;
    }
}
