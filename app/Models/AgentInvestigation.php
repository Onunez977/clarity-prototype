<?php

namespace App\Models;

use App\Data\AgentInvestigation\AgentInvestigationProperties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentInvestigation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'conversation_id',
        'properties',
    ];

    protected $casts = [
        'properties' => AgentInvestigationProperties::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
