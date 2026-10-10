<?php

namespace App\Dto;

use Spatie\LaravelData\Data;

/**
 * @codeCoverageIgnore
 */
class SQSData extends Data
{
    public function __construct(
        public string $now,
        public ?string $vin,
        public string $plate,
        public ?string $state,
        public ?string $us_state,
        public ?string $make,
        public ?string $model,
        public ?string $year,
        public ?string $udbsaid,
        public ?string $hit_type,
        public ?int $scanning_org_id,
        public ?int $hotlist_org_id,
        public ?string $color,
        public ?int $insight_hit_id,
        public ?int $insight_hit_scan_id,
        public ?int $insight_hotlist_id,
        public ?int $insight_kit_id,
        public ?int $insight_integration_id,
        public ?int $insight_org_type, // same as scanning org
        public ?int $insight_scanning_org_type,
        public ?int $insight_hotlist_org_type,
        public ?string $remote_order_id,
        public ?bool $ml_is_not_plate,
        public ?string $ml_plate_state,
        public ?array $scans,
        public $metadata
    ) {
        //
    }
}
