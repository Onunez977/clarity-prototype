<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $org_id
 * @property array<int, array<string, mixed>> $inputs
 * @property string|null $source_vintage
 * @property Carbon|null $materialized_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $geometry_geojson
 * @property-read Org $org
 *
 * @mixin \Eloquent
 */
class OrgRunArea extends Model
{
    use HasFactory;

    protected $connection = 'platform';

    protected $table = 'org_run_areas';

    // Only Backyard-owned columns are mass-assignable; Clarity-owned columns
    // (geometry/source_vintage/materialized_at) flow through Materializer's raw SQL.
    protected $fillable = [
        'org_id',
        'inputs',
    ];

    // Raw PostGIS column serializes as binary EWKB — never want it in API responses.
    protected $hidden = [
        'geometry',
    ];

    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'materialized_at' => 'datetime',
        ];
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    // Schema-qualified `public.*` — platform connection's search_path is `platform`.
    public function scopeWithGeoJson(Builder $query): Builder
    {
        return $query->addSelect([
            'org_run_areas.*',
            DB::raw('public.ST_AsGeoJSON(geometry) AS geometry_geojson'),
        ]);
    }
}
