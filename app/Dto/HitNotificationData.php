<?php

namespace App\Dto;

use App\Models\AlertSetting;
use App\Models\Hit;
use App\Models\MissedOpportunity;
use Spatie\LaravelData\Data;

/**
 * @codeCoverageIgnore
 */
class HitNotificationData extends Data
{
    public function __construct(
        public AlertSetting $alertSetting,
        public Hit|MissedOpportunity $hit,
        public ?SQSData $sqsData = null
    ) {
        //
    }
}
