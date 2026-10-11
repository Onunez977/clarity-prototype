<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Prototype\RecoveryLikelihood;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpportunityDriver extends Model
{
    protected $connection = 'platform';

    protected $table = 'opportunity_drivers';

    protected $guarded = [];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'fix_minutes_ago' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(OpportunityDispatch::class, 'driver_id');
    }

    public function freshGps(): bool
    {
        return $this->fix_minutes_ago < RecoveryLikelihood::DRIVER_FRESH_MINUTES;
    }
}
