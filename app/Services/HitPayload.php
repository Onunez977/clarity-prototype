<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Hit;

class HitPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Hit $hit): array
    {
        $scan = $hit->scan;

        return [
            'now' => now()->toDateTimeString(),
            'vin' => $hit->hotlist?->vin,
            'plate' => $hit->hotlist?->plate,
            'state' => $hit->hotlist?->us_state,
            'us_state' => $hit->hotlist?->us_state,
            'make' => $hit->hotlist?->vehicle_make,
            'model' => $hit->hotlist?->vehicle_model,
            'year' => $hit->hotlist?->vehicle_year,
            'udbsaid' => $hit->kit?->org?->rdn_id,
            'scanning_org_id' => $hit->kit?->org_id,
            'hotlist_org_id' => $hit->hotlist?->org_id,
            'color' => $scan->vehicle_color ?? '',
            'ml_is_not_plate' => $scan->is_not_plate ?? false,
            'ml_plate_state' => $scan->ai_state ?? '',
            'insight_hit_id' => $hit->id,
            'insight_hit_scan_id' => $hit->scan_id,
            'insight_kit_id' => $hit->kit_id,
            'insight_hotlist_id' => $hit->hotlist_id,
            'insight_org_type' => $hit->kit?->org?->type,
            'insight_scanning_org_type' => $hit->kit?->org?->type,
            'insight_hotlist_org_type' => $hit->hotlist?->org?->type,
            'insight_integration_id' => $hit->hotlist?->integration_id,
            'remote_order_id' => $hit->hotlist?->remote_order_id,
            'scans' => $scan ? [
                [
                    'location' => [
                        'lat' => $scan->latitude,
                        'lng' => $scan->longitude,
                    ],
                    'plate_image_url' => scan_img($scan->guid, 'p'),
                    'car_image_url' => scan_img($scan->guid, 'c'),
                    'scanDate' => $scan->scanned_at?->setTimezone($scan->timezone ?? 'UTC')->toIso8601String(),
                    'scanDateUTC' => $scan->scanned_at?->toIso8601String(),
                    'guid' => $scan->guid,
                    'validated' => true,
                    'id' => $scan->id,
                ],
            ] : [],
            'case_claim_url' => '',
        ];
    }
}
