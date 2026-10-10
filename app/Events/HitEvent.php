<?php

namespace App\Events;

use App\Enums\HitTypeEnum;
use App\Models\Hit;
use App\Models\MissedOpportunity;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HitEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public array $scans = [];

    public function __construct(
        public Hit|MissedOpportunity $hit,
        array $scans = [],
    ) {
        $this->scans = $scans;

        if (
            $hit->hit_type === HitTypeEnum::Missed_Opportunity->value
            && \get_class($hit) !== MissedOpportunity::class
        ) {
            $missedOpportunity = new MissedOpportunity;
            $this->hit = $missedOpportunity->forceFill($hit->toArray());
        }
    }
}
