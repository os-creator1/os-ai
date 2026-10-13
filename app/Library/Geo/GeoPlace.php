<?php

namespace App\Library\Geo;

/**
 * One populated place from the bundled GeoNames extract. `geonameId` is the
 * stable geographic identity: it is what separates Springfield, IL from
 * Springfield, MO, and what a saved suggestion is deduplicated on.
 */
final class GeoPlace
{
    public function __construct(
        public readonly int $geonameId,
        public readonly string $name,
        public readonly string $countryCode,
        public readonly string $admin1,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly int $population,
    ) {
    }

    /** Region as people write it: "CA" / "ON" for US and Canada, otherwise null (GeoNames' own numeric codes are not display names). */
    public function regionLabel(): ?string
    {
        return GeoRegions::displayCode($this->countryCode, $this->admin1);
    }

    /** "Irvine, CA" · "Toronto, ON" · "Bristol, United Kingdom". Never a bare name that could match the wrong country. */
    public function label(): string
    {
        $region = $this->regionLabel();

        return $this->name . ', ' . ($region ?? GeoRegions::countryName($this->countryCode));
    }

    /**
     * @return array{geoname_id: int, name: string, label: string, region: ?string, country_code: string, latitude: float, longitude: float}
     */
    public function toArray(): array
    {
        return [
            'geoname_id' => $this->geonameId,
            'name' => $this->name,
            'label' => $this->label(),
            'region' => $this->regionLabel(),
            'country_code' => $this->countryCode,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }

    public function distanceKmTo(self $other): float
    {
        return self::haversine($this->latitude, $this->longitude, $other->latitude, $other->longitude);
    }

    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0088;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
