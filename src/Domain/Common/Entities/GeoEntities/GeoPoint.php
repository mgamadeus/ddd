<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Entities\GeoEntities;

use Brick\Geo\Point;
use DDD\Domain\Base\Entities\ValueObject;
use Override;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;

class GeoPoint extends ValueObject
{
    /** @var float|int Geographical latitude */
    #[GreaterThanOrEqual(-90)]
    #[LessThanOrEqual(90)]
    public float $lat = 0;

    /** @var float|int Geographical longitude */
    #[GreaterThanOrEqual(-180)]
    #[LessThanOrEqual(180)]
    public float $lng = 0;

    public function __construct(float $lat = 0, float $lng = 0, ?string $language = null)
    {
        $this->lat = max(-90, min(90, $lat));
        $this->lng = max(-180, min(180, $lng));
        // intentionally leave out parent constructor call for performance reasons
        //parent::__construct();
    }

    public function __toString(): string
    {
        return $this->lat . ',' . $this->lng;
    }

    public static function fromString(string $lnglat): ?GeoPoint
    {
        return self::fromLatLngString($lnglat);
    }

    /**
     * Creates GeoPoint from comma separated lat,lng pair, only if coordinates are valid
     * @param string $latLng
     * @return GeoPoint|null
     */
    public static function fromLatLngString(string $latLng): ?GeoPoint
    {
        $latLng = explode(',', $latLng);
        [$lat, $lng] = $latLng;
        if (!($lat ?? null)) {
            return null;
        }
        if (!($lng ?? null)) {
            return null;
        }
        $lat = (float)$lat;
        $lng = (float)$lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }
        return new GeoPoint($lat, $lng);
    }

    /**
     * Calculates the great-circle distance between two points, with
     * the Haversine formula.
     * @param GeoPoint $otherGeoPoint
     * @return float
     */
    public function getDistanceInMetersToGeoPoint(GeoPoint $otherGeoPoint): float
    {
        $earthRadius = 6371000;
        // convert from degrees to radians
        $latFrom = deg2rad($this->lat);
        $lonFrom = deg2rad($this->lng);
        $latTo = deg2rad($otherGeoPoint->lat);
        $lonTo = deg2rad($otherGeoPoint->lng);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(
                sqrt(
                    pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
                )
            );
        return $angle * $earthRadius;
    }

    public function uniqueKey(): string
    {
        $key = $this->lat . ',' . $this->lng;
        return self::uniqueKeyStatic($key);
    }

    #[Override] public function mapFromRepository(mixed $repoObject): void
    {
        $this->lng = $repoObject->x();
        $this->lat = $repoObject->y();
    }

    /**
     * @return mixed This method transforms the data to a persistence format. By default JSON is used
     * but in some cases a special format can make sense
     */
    public function mapToRepository(): mixed
    {
        $point = Point::xy($this->lng, $this->lat);
        return $point;
    }

    /**
     * Method is custom implemented for efficiency
     * @param $cached
     * @param bool $returnUniqueKeyInsteadOfContent
     * @param array $path
     * @param bool $ignoreHideAttributes
     * @param bool $ignoreNullValues
     * @param bool $forPersistence
     * @return mixed
     */
    public function toObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true
    ): mixed {
        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];
    }

}