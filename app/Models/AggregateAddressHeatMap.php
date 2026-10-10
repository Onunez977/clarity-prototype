<?php

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\Database\Eloquent\HasPostgisColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $zip_code
 * @property string|null $state
 * @property int|null $population
 * @property float|null $area
 * @property int|null $addresses
 * @property Point|string|null $geom
 * @property-read Address|null $address
 *
 * @method static Builder<static>|AggregateAddressHeatMap newModelQuery()
 * @method static Builder<static>|AggregateAddressHeatMap newQuery()
 * @method static Builder<static>|AggregateAddressHeatMap query()
 * @method static Builder<static>|AggregateAddressHeatMap whereAddresses($value)
 * @method static Builder<static>|AggregateAddressHeatMap whereArea($value)
 * @method static Builder<static>|AggregateAddressHeatMap whereGeom($value)
 * @method static Builder<static>|AggregateAddressHeatMap wherePopulation($value)
 * @method static Builder<static>|AggregateAddressHeatMap whereState($value)
 * @method static Builder<static>|AggregateAddressHeatMap whereZipCode($value)
 *
 * @mixin \Eloquent
 *
 * @codeCoverageIgnore
 */
class AggregateAddressHeatMap extends Model
{
    use HasPostgisColumns;

    protected $connection = 'zod';

    public $timestamps = false;

    protected $table = 'aggregate_address_heatmap';

    protected array $postgisColumns = [
        'geom' => [
            'type' => 'geometry',
            'srid' => 4326,
        ],
    ];

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public static function getGeospatialJson(): Builder
    {
        return self::selectRaw("
        json_build_object(
            'zipcode', aggregate_address_heatmap.zip_code,
            'population', aggregate_address_heatmap.population,
            'area', aggregate_address_heatmap.area,
            'contour', (ST_DUMP(ST_SIMPLIFY(geom,.002))).geom::geometry(Polygon, 4326)::json->'coordinates'->0,
            'weight', CASE
              WHEN (addresses::numeric / (nullif(population::numeric,0) )) * 100 is null THEN 0
              WHEN (addresses::numeric / (nullif(population::numeric,0) )) * 100 < .5 THEN 1
              WHEN (addresses::numeric / (nullif(population::numeric,0) )) * 100 < 2 THEN 2
              WHEN (addresses::numeric / (nullif(population::numeric,0) )) * 100 < 4 THEN 3
              ELSE 4
            END
        ) as json_data")
            ->groupBy('zip_code', 'population', 'area', 'addresses', 'aggregate_address_heatmap.geom');
    }
}
