<?php

declare(strict_types=1);

namespace App\Models;

use Clickbar\Magellan\Data\Geometries\Point;
use Clickbar\Magellan\Database\Eloquent\HasPostgisColumns;
use Geocodio\GeocodioFacade;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @property int $id
 * @property string|null $house_number
 * @property string|null $road
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postcode
 * @property Point|string|null $geo
 * @property string $orig
 * @property int|null $type_id
 * @property-read mixed $formatted_address
 * @property-read Collection<int, Hotlist> $hotlists
 * @property-read int|null $hotlists_count
 *
 * @method static \Database\Factories\AddressFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereGeo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereHouseNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereOrig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address wherePostcode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereRoad($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Address whereTypeId($value)
 *
 * @mixin \Eloquent
 */
class Address extends Model
{
    use HasFactory;
    use HasPostgisColumns;

    protected $connection = 'zod';

    public $timestamps = false;

    protected $table = 'address';

    protected $fillable = [
        'house_number',
        'road',
        'city',
        'state',
        'postcode',
        'geo',
        'orig',
    ];

    protected array $postgisColumns = [
        'geo' => [
            'type' => 'geometry',
            'srid' => 4326,
        ],
    ];

    public function hotlists(): BelongsToMany
    {
        return $this->belongsToMany(Hotlist::class, 'hotlist_address', 'address_id', 'hotlist_id');
    }

    public function formattedAddress(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->house_number.' '.$this->road.', '.$this->city.', '.$this->state.' '.$this->postcode
        );
    }

    public function geocode(): bool
    {
        try {
            $response = GeocodioFacade::geocode($this->orig, [], 1);
            $geo = data_get($response, 'results.0.location');

            $this->geo = DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%s,%s), 4326)',
                $geo->lng,
                $geo->lat
            ));

            $this->house_number = data_get($response, 'results.0.address_components.number');
            $this->road = data_get($response, 'results.0.address_components.street');
            $this->city = data_get($response, 'results.0.address_components.city');
            $this->state = data_get($response, 'results.0.address_components.state');
            $this->postcode = data_get($response, 'results.0.address_components.zip');

            $this->save();

            return true;
        } catch (\Exception $exception) {
            Log::info('Address Geocode: '.$exception->getMessage(), ['address_id' => $this->id]);
        }

        return false;
    }
}
