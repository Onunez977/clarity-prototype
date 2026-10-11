<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpportunityDispatch extends Model
{
    protected $connection = 'platform';

    protected $table = 'opportunity_dispatches';

    protected $guarded = [];

    protected $casts = [
        'stop_order' => 'integer',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(OpportunityCase::class, 'case_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(OpportunityDriver::class, 'driver_id');
    }
}
