<?php

namespace App\Pipelines\HitNotifications;

use App\Dto\HitNotificationData;
use App\Enums\FeatureEnum;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

class ProFilterPipeline
{
    public function __invoke(HitNotificationData $data, \Closure $next)
    {
        Log::channel('hits')->info('ProFilterPipeline - Running', [
            $data->alertSetting->alertable_type,
            $data->alertSetting->alertable_id,
        ]);

        if ($data->alertSetting->alertable instanceof User) {
            if (Feature::for($data->alertSetting->alertable)->inactive(FeatureEnum::ALERT_SETTINGS->slug())) {
                Log::channel('hits')->info('ProFilterPipeline - User is not pro-enabled for alert settings');

                return null;
            }
        }

        return $next($data);
    }
}
