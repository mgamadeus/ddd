<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\GeoEntities;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property GeoPoint[] $elements;
 * @method GeoPoint getByUniqueKey(string $uniqueKey)
 * @method GeoPoint[] getElements
 * @method GeoPoint first
 */
class GeoPoints extends ObjectSet
{
    public function getCenter(): ?GeoPoint
    {
        if (!$this->count()) {
            return null;
        }
        $sumLat = 0.0;
        $sumLng = 0.0;
        foreach ($this->getElements() as $geoPoint) {
            // Sum latitudes and longitudes
            $sumLat += $geoPoint->lat;
            $sumLng += $geoPoint->lng;
        }
        $centerLat = $sumLat / $this->count();
        $centerLng = $sumLng / $this->count();
        return new GeoPoint($centerLat, $centerLng);
    }
}
