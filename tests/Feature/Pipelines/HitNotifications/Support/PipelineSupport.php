<?php

namespace Tests\Feature\Pipelines\HitNotifications\Support;

use App\Dto\HitNotificationData;
use App\Dto\SQSData;
use App\Enums\HitTypeEnum;
use App\Models\MissedOpportunity;
use App\Models\User;

trait PipelineSupport
{
    public function createHitNotificationData(\Closure $callback, ?User $user = null): HitNotificationData
    {
        if (! $user) {
            $user = $this->createUser();
        }

        $missedOpportunity = MissedOpportunity::factory()
            ->forOrg($user->org)
            ->create();

        $missedOpportunity->load('hotlist', 'scan');

        $alertSetting = $callback($missedOpportunity, $user);

        return new HitNotificationData(
            alertSetting: $alertSetting,
            hit: $missedOpportunity,
            sqsData: new SQSData(
                now: now()->toDateTimeString(),
                vin: $missedOpportunity->hotlist->vin,
                plate: $missedOpportunity->hotlist->plate,
                state: $missedOpportunity->hotlist->us_state,
                us_state: $missedOpportunity->hotlist->us_state,
                make: $missedOpportunity->hotlist->vehicle_make,
                model: $missedOpportunity->hotlist->vehicle_model,
                year: $missedOpportunity->hotlist->vehicle_year,
                udbsaid: fake()->uuid(),
                hit_type: HitTypeEnum::Missed_Opportunity->value,
                scanning_org_id: $user->org_id,
                hotlist_org_id: $user->org_id,
                color: fake()->colorName(),
                insight_hit_id: $missedOpportunity->id,
                insight_hit_scan_id: $missedOpportunity->scan_id,
                insight_hotlist_id: $missedOpportunity->hotlist_id,
                insight_kit_id: $missedOpportunity->kit_id,
                insight_integration_id: $missedOpportunity->hotlist->integration_id,
                insight_org_type: $missedOpportunity->hotlist->org->type,
                insight_scanning_org_type: $missedOpportunity->scan->kit->org->type,
                insight_hotlist_org_type: $missedOpportunity->hotlist->org->type,
                remote_order_id: $missedOpportunity->hotlist->remote_order_id,
                ml_is_not_plate: false,
                ml_plate_state: null,
                scans: [$missedOpportunity->scan],
                metadata: [],
            ),
        );
    }
}
