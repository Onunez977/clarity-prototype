<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpportunityHit extends Model
{
    protected $connection = 'platform';

    protected $table = 'opportunity_hits';

    protected $guarded = [];

    protected $casts = [
        'scanned_at' => 'datetime',
        'distance_miles' => 'float',
        'local_hour' => 'integer',
        'state_mismatch' => 'boolean',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(OpportunityCase::class, 'case_id');
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(OpportunityAddress::class, 'address_id');
    }
}
