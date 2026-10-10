<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrgLocationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prototype company location. Nothing here is sent to a live integration.
 *
 * @property int $id
 * @property int $org_id
 * @property string $name
 * @property string $type
 * @property string $address
 * @property string|null $phone
 * @property string|null $notes
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $coverage_mode
 * @property string|null $coverage_radius_miles
 * @property array<string, mixed>|null $coverage_polygon
 */
class OrgLocation extends Model
{
    protected $connection = 'platform';

    protected $table = 'org_locations';

    protected $fillable = [
        'org_id',
        'name',
        'type',
        'address',
        'phone',
        'notes',
        'latitude',
        'longitude',
        'coverage_mode',
        'coverage_radius_miles',
        'coverage_polygon',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'coverage_radius_miles' => 'float',
        'coverage_polygon' => 'array',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function typeEnum(): OrgLocationType
    {
        return OrgLocationType::tryFrom($this->type) ?? OrgLocationType::SatelliteOffice;
    }
}
