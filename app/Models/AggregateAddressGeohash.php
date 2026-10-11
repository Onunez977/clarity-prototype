<?php

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\Database\Eloquent\HasPostgisColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $geohash
 * @property int|null $count
 * @property Point|string|null $geom
 *
 * @method static Builder<static>|AggregateAddressGeohash newModelQuery()
 * @method static Builder<static>|AggregateAddressGeohash newQuery()
 * @method static Builder<static>|AggregateAddressGeohash query()
 * @method static Builder<static>|AggregateAddressGeohash whereCount($value)
 * @method static Builder<static>|AggregateAddressGeohash whereGeohash($value)
 * @method static Builder<static>|AggregateAddressGeohash whereGeom($value)
 *
 * @mixin \Eloquent
 *
 * @codeCoverageIgnore
 */
class AggregateAddressGeohash extends Model
{
    use HasPostgisColumns;

    protected $connection = 'zod';

    protected $table = 'aggregate_address_geohash6';

    protected array $postgisColumns = [
        'geom' => [
            'type' => 'geometry',
            'srid' => 4326,
        ],
    ];

    public static function jsonObject(): Builder
    {
        return self::query()->selectRaw("
          json_build_object(
     'weight', CASE
              WHEN count < 10  THEN 0
              WHEN count < 50 THEN 1
              WHEN count < 100 THEN 2
              WHEN count < 400 THEN 3
              ELSE 4
            END,
    'contour', (ST_DUMP(geom)).geom::geometry(Polygon, 4326)::json->'coordinates'->0
           ) as json_data
        ");
    }

    public static function geojsonQuery()
    {
        return self::query()->fromSub("
        WITH data AS (
  SELECT
    geohash,
    count,
    -- Create color ranges based on count in blocks of 5
    CASE
      WHEN count BETWEEN 1 AND 5 THEN '#E5F5E0'  -- Lightest green
      WHEN count BETWEEN 6 AND 10 THEN '#C7E9C0'
      WHEN count BETWEEN 11 AND 15 THEN '#A1D99B'
      WHEN count BETWEEN 16 AND 20 THEN '#74C476'
      WHEN count BETWEEN 21 AND 25 THEN '#41AB5D'
      WHEN count > 25 THEN '#238B45'             -- Darkest green
      ELSE '#F7FCFD'                             -- Default color
    END AS color,
    -- Convert geohash to geometry
    ST_GeomFromGeoHash(geohash) AS geom
    from aggregate_address_geohash6 where geohash is not null and count >0
),
features AS (
  SELECT
    JSON_BUILD_OBJECT(
      'type', 'Feature',
      'geometry', ST_AsGeoJSON(geom)::json,
      'properties', JSON_BUILD_OBJECT(
        'geohash', geohash,
        'count', count,
        'color', color
      )
    ) AS feature
  FROM data
)
SELECT
  JSON_BUILD_OBJECT(
    'type', 'FeatureCollection',
    'features', JSON_AGG(feature)
  ) AS geojson
FROM features", 'aggregate_address_geohash6')
            ->first()
            ->geojson;
    }

    public static function toGeojson($bounds = null)
    {
        return self::query()
            ->whereNotNull('geohash')
            ->where('count', '>', 0)
            ->when(
                $bounds !== null,
                fn ($query) => $query->whereRaw('ST_Intersects(ST_SetSRID(geom, 4326), ST_GeomFromText(?, 4326))', [$bounds])
            )
            ->selectRaw('ST_AsGeoJSON(geom)::json as geojson, geohash, count')
            ->cursor()
            ->map(function ($row) {
                // Determine color based on count
                $count = $row->count;
                if ($count >= 1 && $count <= 5) {
                    $color = '#A3CFA2';
                } elseif ($count >= 6 && $count <= 10) {
                    $color = '#7FBF7B';
                } elseif ($count >= 11 && $count <= 15) {
                    $color = '#4DAF4A';
                } elseif ($count >= 16 && $count <= 20) {
                    $color = '#2E8B3C';
                } elseif ($count >= 21 && $count <= 25) {
                    $color = '#17632A';
                } elseif ($count > 25) {
                    $color = '#003B1F';
                } else {
                    $color = '#B7D8C2';
                }

                return [
                    'type' => 'Feature',
                    'geometry' => \json_decode($row->geojson),
                    'properties' => [
                        'geohash' => $row->geohash,
                        'count' => $row->count,
                        'color' => $color,
                    ],
                ];
            });
    }
}
