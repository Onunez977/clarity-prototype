<?php

namespace App\Models\Tiger;

use App\Models\Address;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property int $gid
 * @property string $statefp
 * @property string $zcta5ce
 * @property string|null $classfp
 * @property string|null $mtfcc
 * @property string|null $funcstat
 * @property float|null $aland
 * @property float|null $awater
 * @property string|null $intptlat
 * @property string|null $intptlon
 * @property string|null $partflg
 * @property string|null $the_geom
 * @property-read Collection<int, Address> $addresses
 * @property-read int|null $addresses_count
 *
 * @method static Builder<static>|ZipCode5All newModelQuery()
 * @method static Builder<static>|ZipCode5All newQuery()
 * @method static Builder<static>|ZipCode5All query()
 * @method static Builder<static>|ZipCode5All whereAland($value)
 * @method static Builder<static>|ZipCode5All whereAwater($value)
 * @method static Builder<static>|ZipCode5All whereClassfp($value)
 * @method static Builder<static>|ZipCode5All whereFuncstat($value)
 * @method static Builder<static>|ZipCode5All whereGid($value)
 * @method static Builder<static>|ZipCode5All whereIntptlat($value)
 * @method static Builder<static>|ZipCode5All whereIntptlon($value)
 * @method static Builder<static>|ZipCode5All whereMtfcc($value)
 * @method static Builder<static>|ZipCode5All wherePartflg($value)
 * @method static Builder<static>|ZipCode5All whereStatefp($value)
 * @method static Builder<static>|ZipCode5All whereTheGeom($value)
 * @method static Builder<static>|ZipCode5All whereZcta5ce($value)
 * @method static Builder<static>|ZipCode5All withShape(string $shape)
 *
 * @codeCoverageIgnore
 *
 * @mixin \Eloquent
 */
class ZipCode5All extends Model
{
    protected $table = 'tiger_data.zcta5_all';

    protected $connection = 'zod';

    protected $primaryKey = 'gid';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function scopeWithShape(Builder $builder, string $shape)
    {
        $builder->selectRaw('ST_AsGeoJSON(ST_GeometryN(the_geom, generate_series(1, ST_NumGeometries(the_geom)))) AS shape, *');
    }

    public function addresses()
    {
        return $this->hasMany(Address::class, 'postcode', 'zcta5ce');
    }

    public static function getHeatMapData()
    {

        $sub_query = ZipCode5All::query()
            ->select('aland', 'the_geom', 'zcta5ce')
            ->withCount('addresses')
            ->groupBy('zcta5ce', 'aland', 'the_geom');

        $query = DB::connection('zod')
            ->table('tiger_data.zcta5_all')
            ->selectRaw("
            json_build_object(
        'zipcode', zcta5ce,
        'population', '1235456',
        'area', ceil(aland * 0.00000038610215855),
        'addresses', t.addresses_count,
        'contour', ST_CollectionExtract(
        ST_Collect(the_geom)
      )::json -> 'coordinates'->0->0
        ) as data
            ")
            ->fromSub($sub_query, 't')
            ->groupBy('zcta5ce', 'aland', 'the_geom', 't.addresses_count');

        // return $query;
        return ZipCode5All::query()->toBase()->selectRaw('json_agg(t2.data)')->fromSub($query, 't2');

    }
}
