<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OpportunityCase extends Model
{
    protected $connection = 'platform';

    protected $table = 'opportunity_cases';

    protected $guarded = [];

    protected $casts = [
        'order_date' => 'date',
        'vehicle_year' => 'integer',
    ];

    public function addresses(): HasMany
    {
        return $this->hasMany(OpportunityAddress::class, 'case_id')->orderBy('position');
    }

    public function hits(): HasMany
    {
        return $this->hasMany(OpportunityHit::class, 'case_id')->orderByDesc('scanned_at');
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(OpportunityCheckIn::class, 'case_id')->orderByDesc('matched_at');
    }

    public function dispatch(): HasOne
    {
        return $this->hasOne(OpportunityDispatch::class, 'case_id');
    }
}
