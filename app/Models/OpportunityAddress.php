<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpportunityAddress extends Model
{
    protected $connection = 'platform';

    protected $table = 'opportunity_addresses';

    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(OpportunityCase::class, 'case_id');
    }
}
