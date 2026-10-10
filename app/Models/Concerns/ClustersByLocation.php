<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

trait ClustersByLocation
{
    public static function clusterTimestampField(): string
    {
        return 'scanned_at';
    }

    public static function clusterGroupComponent(): string
    {
        return Str::plural(Str::lower(class_basename(static::class))).'.cluster-group';
    }

    public static function clusterByLocation(
        $records,
        float $miles = 0.1,
        string $timestampField = 'scanned_at',
        string $addressField = 'address',
    ): mixed {
        $recordsWithRange = $records->map(function ($record) use ($miles) {
            $record->coordinate_range = $record->getCoordinateRanges($miles);

            return $record;
        });

        return $recordsWithRange
            ->groupBy(function ($record) use ($recordsWithRange) {
                $filtered = $recordsWithRange
                    ->pluck('coordinate_range')
                    ->filter(function ($range) use ($record) {
                        return (($range['lat_min'] < $record->latitude) && ($record->latitude < $range['lat_max']))
                            && (($range['lon_min'] < $record->longitude) && ($record->longitude < $range['lon_max']));
                    });

                return $filtered->first()['relative_centerpoint'] ?? $record->coordinate_range['relative_centerpoint'];
            })
            ->map(function ($group) use ($timestampField, $addressField) {
                return $group
                    ->sortByDesc(fn ($r) => $r->{$timestampField})
                    ->reduce(function ($filtered, $record) use ($timestampField, $addressField) {
                        $timestamp = $record->{$timestampField} instanceof Carbon
                            ? $record->{$timestampField}
                            : Carbon::parse($record->{$timestampField});

                        $existingMatch = $filtered->first(function ($existing) use ($record, $timestamp, $timestampField, $addressField) {
                            if ($existing->{$addressField} && $record->{$addressField}) {
                                $check = $existing->{$addressField} === $record->{$addressField};
                            } else {
                                $check = $existing->latitude === $record->latitude
                                    && $existing->longitude === $record->longitude;
                            }

                            $existingTs = $existing->{$timestampField} instanceof Carbon
                                ? $existing->{$timestampField}
                                : Carbon::parse($existing->{$timestampField});

                            return $check && abs($timestamp->diffInMinutes($existingTs)) < 45;
                        });

                        if ($existingMatch) {
                            $existingMatch->same_location_count = ($existingMatch->same_location_count ?? 0) + 1;
                        } else {
                            $record->same_location_count = 0;
                            $filtered->push($record);
                        }

                        return $filtered;
                    }, collect());
            });
    }

    public function getCoordinateRanges(float $miles): array
    {
        $oneDegree = 69;
        $lat = (float) $this->latitude;
        $lon = (float) $this->longitude;

        $lon_min = $lon - $miles / abs(cos(deg2rad($lat)) * $oneDegree);
        $lon_max = $lon + $miles / abs(cos(deg2rad($lat)) * $oneDegree);
        $lat_min = $lat - ($miles / $oneDegree);
        $lat_max = $lat + ($miles / $oneDegree);

        return [
            'lat_max' => $lat_max,
            'lat_min' => $lat_min,
            'lon_max' => $lon_max,
            'lon_min' => $lon_min,
            'relative_centerpoint' => (($lat_min + $lat_max) / 2).', '.(($lon_min + $lon_max) / 2),
        ];
    }
}
