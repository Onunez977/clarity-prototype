<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpportunityCheckIn extends Model
{
    protected $connection = 'platform';

    protected $table = 'opportunity_check_ins';

    protected $guarded = [];

    protected $casts = [
        'matched_at' => 'datetime',
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
