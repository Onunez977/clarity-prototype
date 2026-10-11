<?php

namespace Database\Seeders;

use App\Enums\OrgLocationType;
use App\Models\Org;
use App\Models\OrgCoverageZone;
use App\Models\OrgLocation;
use Illuminate\Database\Seeder;

class OrgLocationSeeder extends Seeder
{
    public function run(): void
    {
        $org = Org::query()->where('name', 'Test Company')->first();

        if (! $org || OrgLocation::query()->where('org_id', $org->id)->exists()) {
            return;
        }

        $main = $this->place($org->id, [
            'name' => 'Test Company HQ',
            'type' => OrgLocationType::MainOffice->value,
            'address' => '120 Monument Circle, Indianapolis, IN 46204',
            'phone' => '(317) 555-0142',
            'notes' => 'Front desk and dispatch.',
            'latitude' => 39.768403,
            'longitude' => -86.158066,
        ]);

        $this->place($org->id, [
            'name' => 'East Yard',
            'type' => OrgLocationType::StorageFacility->value,
            'address' => '1840 N Sherman Dr, Indianapolis, IN 46201',
            'phone' => '(317) 555-0168',
            'notes' => 'Overnight camera storage.',
            'latitude' => 39.780500,
            'longitude' => -86.108200,
            'coverage_mode' => 'polygon',
            'coverage_polygon' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [-86.130, 39.795],
                    [-86.090, 39.795],
                    [-86.090, 39.765],
                    [-86.130, 39.765],
                    [-86.130, 39.795],
                ]],
            ],
        ]);

        $this->place($org->id, [
            'name' => 'West Lot',
            'type' => OrgLocationType::StorageFacility->value,
            'address' => '5102 W 10th St, Indianapolis, IN 46224',
            'phone' => '(317) 555-0194',
            'notes' => 'Spare kits and vehicle bay.',
            'latitude' => 39.777200,
            'longitude' => -86.228400,
        ]);

        $this->place($org->id, [
            'name' => 'North Satellite',
            'type' => OrgLocationType::SatelliteOffice->value,
            'address' => '8601 N Michigan Rd, Indianapolis, IN 46268',
            'phone' => '(317) 555-0117',
            'notes' => 'Client drop-off desk.',
            'latitude' => 39.909200,
            'longitude' => -86.204700,
            'coverage_mode' => 'radius',
            'coverage_radius_miles' => 8,
        ]);

        OrgCoverageZone::query()->updateOrCreate(
            ['org_id' => $org->id],
            [
                'scope' => 'company',
                'mode' => 'radius',
                'radius_miles' => 25,
                'center_location_id' => $main->id,
                'polygon' => null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function place(int $orgId, array $attributes): OrgLocation
    {
        return OrgLocation::query()->create(['org_id' => $orgId] + $attributes);
    }
}
