<?php

namespace App\Pipelines\HitNotifications;

use App\Dto\HitNotificationData;
use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use Illuminate\Support\Facades\Log;

class HotlistStatusFilterPipeline
{
    public function __invoke(HitNotificationData $data, \Closure $next)
    {
        if ($data->hit->hit_type !== HitTypeEnum::Missed_Opportunity->value) {
            return $next($data);
        }

        $data->hit->loadMissing('hotlist');

        $status = $data->hit->hotlist
            ? HotlistStatusEnum::tryFrom($data->hit->hotlist->status)
            : null;

        if ($status?->isTerminal()) {
            Log::channel('hits')->info('HotlistStatusFilterPipeline - Suppressing Missed Opportunity alert for terminal hotlist', [
                'hotlist_id' => $data->hit->hotlist_id,
                'status' => $status->name,
            ]);

            return null;
        }

        return $next($data);
    }
}
