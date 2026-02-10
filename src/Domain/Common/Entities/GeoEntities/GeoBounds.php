<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\GeoEntities;

use DDD\Domain\Base\Entities\ValueObject;

class GeoBounds extends ValueObject
{
    /** @var GeoPoint|null Noth eastern bounding point */
    public ?GeoPoint $northeast;

    /** @var GeoPoint|null Noth western bounding point */
    public ?GeoPoint $southwest;

    public function __construct()
    {
        $this->northeast = new GeoPoint();
        $this->southwest = new GeoPoint();
        parent::__construct();
    }

}