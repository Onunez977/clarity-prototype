<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VehicleTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $kit_id
 * @property int|null $user_id
 * @property VehicleTypeEnum|null $vehicle_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Kit|null $kit
 * @property-read User|null $user
 *
 * @mixin \Eloquent
 */
class KitSettings extends Model
{
    protected $connection = 'platform';

    protected $table = 'platform.kit_settings';

    protected $primaryKey = 'kit_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'kit_id',
        'user_id',
        'vehicle_type',
    ];

    protected $casts = [
        'vehicle_type' => VehicleTypeEnum::class,
    ];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class, 'kit_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
