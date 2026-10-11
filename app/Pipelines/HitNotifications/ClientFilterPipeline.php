<?php

namespace App\Pipelines\HitNotifications;

use App\Dto\HitNotificationData;
use App\Enums\HitTypeEnum;
use Illuminate\Support\Facades\Log;

class ClientFilterPipeline
{
    public function __invoke(HitNotificationData $data, \Closure $next)
    {
        if (\in_array($data->hit->hit_type, [HitTypeEnum::Staging_Live->value, HitTypeEnum::Staging_Historical->value])) {
            Log::channel('hits')->info('ClientFilterPipeline - Bypassing client filter for staging hit type', [
                'hit_type' => $data->hit->hit_type,
            ]);

            return $next($data);
        }

        Log::channel('hits')->info('ClientFilterPipeline - Running', $data->alertSetting->settings->clients);

        if (\count($data->alertSetting->settings->clients) > 0 && ! \in_array($data->hit->hotlist?->client_org_id, $data->alertSetting->settings->clients)) {
            Log::channel('hits')->info('ClientFilterPipeline - Client is not in the list of clients for the alert setting');

            return null;
        }

        return $next($data);
    }
}
