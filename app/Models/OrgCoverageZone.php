<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One coverage choice per organization: company-wide, or per location.
 *
 * @property int $id
 * @property int $org_id
 * @property string $scope
 * @property string $mode
 * @property string|null $radius_miles
 * @property int|null $center_location_id
 * @property array<string, mixed>|null $polygon
 */
class OrgCoverageZone extends Model
{
    protected $connection = 'platform';

    protected $table = 'org_coverage_zones';

    protected $fillable = [
        'org_id',
        'scope',
        'mode',
        'radius_miles',
        'center_location_id',
        'polygon',
    ];

    protected $casts = [
        'radius_miles' => 'float',
        'polygon' => 'array',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(OrgLocation::class, 'center_location_id');
    }
}
