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

    public function __construct(float $lat = 0, float $lng = 0)
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
     * @return static|null
     */
    public static function fromLatLngString(string $latLng): ?static
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
        return new static($lat, $lng);
    }

    /**
     * Calculates the great-circle distance between two points with the Haversine formula,
     * using the WGS84 geocentric radius at the midpoint latitude instead of the mean
     * spherical radius. This removes the ~0.3% latitude-dependent error of the spherical
     * model while keeping the formula closed-form (no iteration).
     *
     * @param GeoPoint $otherGeoPoint
     * @return float Distance in meters
     */
    public function getDistanceInMetersToGeoPoint(GeoPoint $otherGeoPoint): float
    {
        // WGS84 ellipsoid parameters
        $a = 6378137.0;          // equatorial radius (m)
        $b = 6356752.314245;     // polar radius (m)

        $latFrom = deg2rad($this->lat);
        $lonFrom = deg2rad($this->lng);
        $latTo = deg2rad($otherGeoPoint->lat);
        $lonTo = deg2rad($otherGeoPoint->lng);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        // WGS84 geocentric radius at midpoint latitude:
        // R(φ) = sqrt( ((a²·cosφ)² + (b²·sinφ)²) / ((a·cosφ)² + (b·sinφ)²) )
        $midLat = ($latFrom + $latTo) / 2;
        $cosMid = cos($midLat);
        $sinMid = sin($midLat);
        $aCos = $a * $cosMid;
        $bSin = $b * $sinMid;
        $aSqCos = $a * $aCos;
        $bSqSin = $b * $bSin;
        $earthRadius = sqrt(($aSqCos * $aSqCos + $bSqSin * $bSqSin) / ($aCos * $aCos + $bSin * $bSin));

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
     * @param int $flags
     * @return mixed
     */
    public function toObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true,
        int $flags = 0
    ): mixed {
        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
        ];
    }
}