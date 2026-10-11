<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\OpportunityAddress;
use App\Models\OpportunityCase;
use App\Models\OpportunityCheckIn;
use App\Models\OpportunityDispatch;
use App\Models\OpportunityDriver;
use App\Models\OpportunityHit;
use App\Models\Org;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sample open assignments for the Opportunities prototype.
 * Signals are stored here; RecoveryLikelihood scores them at read time.
 */
class OpportunitySeeder extends Seeder
{
    public function run(): void
    {
        $org = Org::query()->where('name', 'Test Company')->first();
        if (! $org) {
            return;
        }

        if (OpportunityCase::query()->where('org_id', $org->id)->exists()) {
            return;
        }

        $this->call(OrgLocationSeeder::class);

        $drivers = $this->drivers($org->id);
        $now = now();

        $featured = [
            $this->spec('ABC1234', 'Summit Auto Finance', 'Indianapolis', 14, 2019, 'Honda', 'Civic', '48211', [
                ['line' => '120 Main St', 'city' => 'Indianapolis', 'lat' => 39.8662, 'lng' => -86.1494],
                ['line' => '88 Oak Ave', 'city' => 'Indianapolis', 'lat' => 39.8712, 'lng' => -86.1416],
            ], [
                ['address' => 2, 'hours' => 6, 'type' => 'L', 'miles' => 0.04, 'hour' => 2, 'kit' => 'North 4', 'state_mismatch' => true],
                ['address' => 2, 'hours' => 30, 'type' => 'L', 'miles' => 0.05, 'hour' => 3, 'kit' => 'North 4', 'state_mismatch' => true],
                ['address' => 2, 'hours' => 24 * 8, 'type' => 'L', 'miles' => 0.06, 'hour' => 1, 'kit' => 'North 4'],
                ['address' => 2, 'hours' => 24 * 120, 'type' => 'H', 'miles' => 0.08, 'hour' => 2, 'kit' => 'North 4'],
            ], [
                ['address' => 1, 'hours' => 20, 'kit' => 'East 2'],
            ]),
            $this->spec('RKT4410', 'Harbor Credit', 'Indianapolis', 6, 2020, 'Toyota', 'Camry', '48240', [
                ['line' => '521 E Washington St', 'city' => 'Indianapolis', 'lat' => 39.7734, 'lng' => -86.1488],
            ], [
                ['address' => 1, 'hours' => 3, 'type' => 'L', 'miles' => 0.05, 'hour' => 19, 'kit' => 'East 2'],
                ['address' => 1, 'hours' => 26, 'type' => 'L', 'miles' => 0.07, 'hour' => 20, 'kit' => 'East 2'],
            ]),
            $this->spec('HND8821', 'Northline Acceptance', 'Indianapolis', 9, 2018, 'Nissan', 'Altima', '48302', [
                ['line' => '412 S Audubon Rd', 'city' => 'Indianapolis', 'lat' => 39.7684, 'lng' => -86.0702],
            ], [
                ['address' => 1, 'hours' => 5, 'type' => 'L', 'miles' => 0.08, 'hour' => 8, 'kit' => 'East 2'],
                ['address' => 1, 'hours' => 28, 'type' => 'L', 'miles' => 0.09, 'hour' => 7, 'kit' => 'East 2'],
                ['address' => 1, 'hours' => 24 * 4, 'type' => 'L', 'miles' => 0.09, 'hour' => 9, 'kit' => 'East 2'],
                ['address' => 1, 'hours' => 24 * 6, 'type' => 'L', 'miles' => 0.08, 'hour' => 8, 'kit' => 'East 2'],
            ], [
                ['address' => 1, 'hours' => 24 * 3, 'kit' => 'East 2'],
            ]),
            $this->spec('PLM2209', 'Red Oak Lending', 'Fort Wayne', 2, 2021, 'Ford', 'F-150', '48410', [
                ['line' => '2400 W 16th St', 'city' => 'Speedway', 'lat' => 39.7938, 'lng' => -86.2480],
            ], [
                ['address' => 1, 'hours' => 2, 'type' => 'L', 'miles' => 0.04, 'hour' => 15, 'kit' => 'West 1'],
            ]),
            $this->spec('OLD4410', 'Pioneer Motor Credit', 'Evansville', 40, 2016, 'Chevrolet', 'Malibu', '47002', [
                ['line' => '180 S College Ave', 'city' => 'Bloomington', 'lat' => 39.1653, 'lng' => -86.5264],
            ], [
                ['address' => 1, 'hours' => 24 * 120, 'type' => 'H', 'miles' => 0.40, 'hour' => 14, 'kit' => 'South 7'],
            ]),
            $this->spec('BAD1001', 'Summit Auto Finance', 'Indianapolis', 20, 2017, 'Hyundai', 'Elantra', '47088', [
                ['line' => '310 W Kirkwood Ave', 'city' => 'Bloomington', 'lat' => 39.1665, 'lng' => -86.5340],
            ], [
                ['address' => 1, 'hours' => 4, 'type' => 'L', 'miles' => 0.05, 'hour' => 11, 'kit' => 'South 7', 'disposition' => 'invalid_scan'],
            ]),
            $this->spec('MOV2200', 'Harbor Credit', 'Indianapolis', 8, 2019, 'Kia', 'Sorento', '48110', [
                ['line' => '640 Massachusetts Ave', 'city' => 'Indianapolis', 'lat' => 39.7734, 'lng' => -86.1488],
            ], [
                ['address' => 1, 'hours' => 5, 'type' => 'L', 'miles' => 0.40, 'hour' => 16, 'kit' => 'North 4', 'disposition' => 'moving'],
            ]),
            $this->spec('FUZ3390', 'Northline Acceptance', 'Fort Wayne', 4, 2022, 'Jeep', 'Cherokee', '48550', [
                ['line' => '900 E 86th St', 'city' => 'Indianapolis', 'lat' => 39.9105, 'lng' => -86.1402],
            ], [
                ['address' => 1, 'hours' => 2, 'type' => 'P', 'miles' => 0.02, 'hour' => 13, 'kit' => 'North 4'],
            ]),
            $this->spec('OUT5501', 'Red Oak Lending', 'Evansville', 5, 2018, 'Dodge', 'Charger', '47901', [
                ['line' => '400 Main St', 'city' => 'Lafayette', 'lat' => 40.4167, 'lng' => -86.8753],
            ], [
                ['address' => 1, 'hours' => 10, 'type' => 'L', 'miles' => 0.06, 'hour' => 1, 'kit' => 'North 4'],
                ['address' => 1, 'hours' => 50, 'type' => 'L', 'miles' => 0.07, 'hour' => 3, 'kit' => 'North 4'],
            ]),
            $this->spec('CHK6602', 'Pioneer Motor Credit', 'Indianapolis', 11, 2015, 'Honda', 'Accord', '48620', [
                ['line' => '1100 Prospect St', 'city' => 'Indianapolis', 'lat' => 39.7522, 'lng' => -86.1401],
            ], [
                ['address' => 1, 'hours' => 30, 'type' => 'L', 'miles' => 0.20, 'hour' => 14, 'kit' => 'East 2'],
            ], [
                ['address' => 1, 'hours' => 5, 'kit' => 'East 2'],
            ]),
            $this->spec('UNG7703', 'Summit Auto Finance', 'Indianapolis', 2, 2020, 'Toyota', 'RAV4', '48770', [
                ['line' => 'PO Box 12', 'city' => 'Indianapolis', 'lat' => null, 'lng' => null],
                ['line' => '200 E Main St', 'city' => 'Greenwood', 'lat' => 39.6136, 'lng' => -86.1066],
            ]),
            $this->spec('STALE8804', 'Harbor Credit', 'Fort Wayne', 60, 2014, 'Ford', 'Escape', '46014', [
                ['line' => '150 W Main St', 'city' => 'Carmel', 'lat' => 39.9784, 'lng' => -86.1180],
            ], [
                ['address' => 1, 'hours' => 24 * 20, 'type' => 'H', 'miles' => 2.0, 'hour' => 22, 'kit' => 'North 4'],
            ]),
            $this->spec('N4A101', 'Summit Auto Finance', 'Indianapolis', 7, 2019, 'Chevrolet', 'Silverado', '49101', [
                ['line' => '8600 Evergreen Ave', 'city' => 'Indianapolis', 'lat' => 39.9105, 'lng' => -86.1402],
            ], [
                ['address' => 1, 'hours' => 60, 'type' => 'L', 'miles' => 0.40, 'hour' => 18, 'kit' => 'North 4'],
            ], [], ['driver' => 'Jordan Hale', 'status' => 'dispatched', 'priority' => 'high', 'due' => 'tonight', 'note' => 'Start at the north end of the street.', 'stop' => 1]),
            $this->spec('N4A102', 'Harbor Credit', 'Indianapolis', 5, 2021, 'Honda', 'CR-V', '49102', [
                ['line' => '6120 N College Ave', 'city' => 'Indianapolis', 'lat' => 39.8695, 'lng' => -86.1430],
            ], [
                ['address' => 1, 'hours' => 60, 'type' => 'L', 'miles' => 0.35, 'hour' => 17, 'kit' => 'North 4'],
            ], [], ['driver' => 'Jordan Hale', 'status' => 'dispatched', 'priority' => 'normal', 'due' => 'tonight', 'note' => 'Second stop if College is quiet.', 'stop' => 2]),
            $this->spec('N4A103', 'Northline Acceptance', 'Indianapolis', 12, 2017, 'Nissan', 'Rogue', '49103', [
                ['line' => '906 Virginia Ave', 'city' => 'Indianapolis', 'lat' => 39.7522, 'lng' => -86.1401],
            ], [
                ['address' => 1, 'hours' => 60, 'type' => 'L', 'miles' => 0.45, 'hour' => 13, 'kit' => 'North 4'],
            ], [], ['driver' => 'Jordan Hale', 'status' => 'dispatched', 'priority' => 'normal', 'due' => 'tomorrow', 'note' => 'South side, after the other two.', 'stop' => 3]),
            $this->spec('E2B201', 'Red Oak Lending', 'Indianapolis', 9, 2018, 'Hyundai', 'Tucson', '49201', [
                ['line' => '5720 E Washington St', 'city' => 'Indianapolis', 'lat' => 39.7690, 'lng' => -86.0760],
            ], [
                ['address' => 1, 'hours' => 70, 'type' => 'L', 'miles' => 0.30, 'hour' => 8, 'kit' => 'East 2'],
            ], [], ['driver' => 'Priya Shah', 'status' => 'dispatched', 'priority' => 'high', 'due' => 'overnight', 'note' => 'Overnight window.', 'stop' => 1]),
            $this->spec('E2B202', 'Pioneer Motor Credit', 'Indianapolis', 4, 2016, 'Kia', 'Sportage', '49202', [
                ['line' => '6100 E 10th St', 'city' => 'Indianapolis', 'lat' => 39.7810, 'lng' => -86.0820],
            ], [
                ['address' => 1, 'hours' => 40, 'type' => 'L', 'miles' => 0.25, 'hour' => 9, 'kit' => 'East 2'],
            ], [
                ['address' => 1, 'hours' => 2, 'kit' => 'East 2'],
            ], ['driver' => 'Priya Shah', 'status' => 'checked_in', 'priority' => 'normal', 'due' => 'tonight', 'note' => 'Checked the driveway at dusk.', 'stop' => 2]),
            $this->spec('REC301', 'Summit Auto Finance', 'Indianapolis', 15, 2015, 'Ford', 'Fusion', '49301', [
                ['line' => '5900 W 16th St', 'city' => 'Speedway', 'lat' => 39.7945, 'lng' => -86.2500],
            ], [
                ['address' => 1, 'hours' => 24 * 3, 'type' => 'L', 'miles' => 0.10, 'hour' => 11, 'kit' => 'West 1'],
            ], [], ['driver' => 'Marcus Webb', 'status' => 'recovered', 'priority' => 'high', 'due' => 'tonight', 'note' => 'Recovered in the side lot.', 'stop' => 1]),
        ];

        DB::connection('platform')->transaction(function () use ($featured, $org, $drivers, $now): void {
            foreach ($featured as $spec) {
                $this->store($org->id, $spec, $drivers, $now);
            }
            $this->generated($org->id, $drivers, $now);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $addresses
     * @param  list<array<string, mixed>>  $hits
     * @param  list<array<string, mixed>>  $checkIns
     * @param  array<string, mixed>|null  $dispatch
     * @return array<string, mixed>
     */
    private function spec(
        string $plate,
        string $client,
        string $branch,
        int $orderDays,
        int $year,
        string $make,
        string $model,
        string $remote,
        array $addresses,
        array $hits = [],
        array $checkIns = [],
        ?array $dispatch = null,
    ): array {
        return compact('plate', 'client', 'branch', 'orderDays', 'year', 'make', 'model', 'remote', 'addresses', 'hits', 'checkIns', 'dispatch');
    }

    /**
     * @return array<string, OpportunityDriver>
     */
    private function drivers(int $orgId): array
    {
        $org = Org::query()->findOrFail($orgId);
        $rows = [
            ['name' => 'Jordan Hale', 'email' => 'jordan.hale@prototype.test', 'kit' => 'North 4', 'lat' => 39.8680, 'lng' => -86.1450, 'fix' => 12],
            ['name' => 'Priya Shah', 'email' => 'priya.shah@prototype.test', 'kit' => 'East 2', 'lat' => 39.7700, 'lng' => -86.0780, 'fix' => 35],
            ['name' => 'Marcus Webb', 'email' => 'marcus.webb@prototype.test', 'kit' => 'West 1', 'lat' => 39.7920, 'lng' => -86.2460, 'fix' => 8],
            ['name' => 'Alex Nguyen', 'email' => 'alex.nguyen@prototype.test', 'kit' => 'South 7', 'lat' => 39.7200, 'lng' => -86.1500, 'fix' => 1800],
        ];

        $drivers = [];
        foreach ($rows as $row) {
            $user = User::query()->where('email', $row['email'])->first();
            if (! $user) {
                $user = User::factory()->forOrg($org)->create([
                    'name' => $row['name'],
                    'email' => $row['email'],
                ]);
            }

            $drivers[$row['name']] = OpportunityDriver::query()->create([
                'org_id' => $orgId,
                'user_id' => $user->id,
                'name' => $row['name'],
                'kit_name' => $row['kit'],
                'latitude' => $row['lat'],
                'longitude' => $row['lng'],
                'fix_minutes_ago' => $row['fix'],
            ]);
        }

        return $drivers;
    }

    /**
     * @param  array<string, OpportunityDriver>  $drivers
     */
    private function generated(int $orgId, array $drivers, Carbon $now): void
    {
        $inside = [
            ['city' => 'Indianapolis', 'lat' => 39.8692, 'lng' => -86.1435],
            ['city' => 'Indianapolis', 'lat' => 39.7522, 'lng' => -86.1401],
            ['city' => 'Indianapolis', 'lat' => 39.7684, 'lng' => -86.0702],
            ['city' => 'Speedway', 'lat' => 39.7938, 'lng' => -86.2480],
            ['city' => 'Indianapolis', 'lat' => 39.7734, 'lng' => -86.1488],
            ['city' => 'Indianapolis', 'lat' => 39.9105, 'lng' => -86.1402],
            ['city' => 'Greenwood', 'lat' => 39.6136, 'lng' => -86.1066],
            ['city' => 'Carmel', 'lat' => 39.9784, 'lng' => -86.1180],
        ];
        $outside = [
            ['city' => 'Bloomington', 'lat' => 39.1653, 'lng' => -86.5264],
            ['city' => 'Lafayette', 'lat' => 40.4167, 'lng' => -86.8753],
            ['city' => 'Muncie', 'lat' => 40.1934, 'lng' => -85.3863],
            ['city' => 'Terre Haute', 'lat' => 39.4667, 'lng' => -87.4139],
            ['city' => 'Columbus', 'lat' => 39.2014, 'lng' => -85.9214],
        ];
        $clients = ['Summit Auto Finance', 'Harbor Credit', 'Northline Acceptance', 'Red Oak Lending', 'Pioneer Motor Credit'];
        $branches = ['Indianapolis', 'Fort Wayne', 'Evansville'];
        $vehicles = [
            [2019, 'Honda', 'Civic'],
            [2018, 'Toyota', 'Corolla'],
            [2020, 'Ford', 'Explorer'],
            [2017, 'Chevrolet', 'Equinox'],
            [2021, 'Nissan', 'Sentra'],
        ];

        for ($i = 0; $i < 34; $i++) {
            $useOutside = $i % 4 === 0;
            $place = $useOutside ? $outside[$i % count($outside)] : $inside[$i % count($inside)];
            $lat = $place['lat'] + (($i % 5) - 2) * 0.008;
            $lng = $place['lng'] + (($i % 3) - 1) * 0.008;
            $vehicle = $vehicles[$i % count($vehicles)];
            $pattern = $i % 7;
            $hits = match ($pattern) {
                0 => [
                    ['address' => 1, 'hours' => 36, 'type' => 'L', 'miles' => 0.20, 'hour' => 9, 'kit' => 'North 4'],
                    ['address' => 1, 'hours' => 80, 'type' => 'L', 'miles' => 0.22, 'hour' => 18, 'kit' => 'North 4'],
                ],
                1 => [
                    ['address' => 1, 'hours' => 96, 'type' => 'L', 'miles' => 0.50, 'hour' => 15, 'kit' => 'East 2'],
                ],
                2 => [
                    ['address' => 1, 'hours' => 24 * 18, 'type' => 'H', 'miles' => 0.30, 'hour' => 11, 'kit' => 'South 7'],
                ],
                3 => [
                    ['address' => 1, 'hours' => 8, 'type' => 'L', 'miles' => 0.50, 'hour' => 10, 'kit' => 'West 1', 'disposition' => 'invalid_scan'],
                ],
                4 => [
                    ['address' => 1, 'hours' => 60, 'type' => 'L', 'miles' => 0.40, 'hour' => 16, 'kit' => 'North 4', 'disposition' => 'moving'],
                ],
                5 => [
                    ['address' => 1, 'hours' => 6, 'type' => 'P', 'miles' => 0.03, 'hour' => 12, 'kit' => 'East 2'],
                ],
                default => [
                    ['address' => 1, 'hours' => 100, 'type' => 'L', 'miles' => 0.08, 'hour' => 21, 'kit' => 'West 1'],
                ],
            };

            $this->store($orgId, $this->spec(
                'K'.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
                $clients[$i % count($clients)],
                $branches[$i % count($branches)],
                3 + ($i % 20),
                $vehicle[0],
                $vehicle[1],
                $vehicle[2],
                (string) (50000 + $i),
                [
                    ['line' => (100 + $i * 10).' Sample St', 'city' => $place['city'], 'lat' => $lat, 'lng' => $lng],
                ],
                $hits,
            ), $drivers, $now);
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, OpportunityDriver>  $drivers
     */
    private function store(int $orgId, array $spec, array $drivers, Carbon $now): void
    {
        $case = OpportunityCase::query()->create([
            'org_id' => $orgId,
            'plate' => $spec['plate'],
            'us_state' => 'IN',
            'vin' => '1HGCM82633A'.substr(preg_replace('/\D/', '', $spec['remote']) ?: '00000', 0, 5),
            'vehicle_year' => $spec['year'],
            'vehicle_make' => $spec['make'],
            'vehicle_model' => $spec['model'],
            'client_name' => $spec['client'],
            'branch_name' => $spec['branch'],
            'remote_order_id' => $spec['remote'],
            'order_date' => $now->copy()->subDays($spec['orderDays'])->toDateString(),
        ]);

        $addressIds = [];
        foreach ($spec['addresses'] as $index => $address) {
            $row = OpportunityAddress::query()->create([
                'case_id' => $case->id,
                'position' => $index + 1,
                'line' => $address['line'],
                'city' => $address['city'],
                'state' => 'IN',
                'latitude' => $address['lat'],
                'longitude' => $address['lng'],
            ]);
            $addressIds[$index + 1] = $row->id;
        }

        foreach ($spec['hits'] as $hit) {
            OpportunityHit::query()->create([
                'case_id' => $case->id,
                'address_id' => $addressIds[$hit['address']] ?? null,
                'scanned_at' => $now->copy()->subHours($hit['hours']),
                'hit_type' => $hit['type'],
                'distance_miles' => $hit['miles'],
                'local_hour' => $hit['hour'],
                'disposition' => $hit['disposition'] ?? null,
                'state_mismatch' => (bool) ($hit['state_mismatch'] ?? false),
                'kit_name' => $hit['kit'] ?? null,
            ]);
        }

        foreach ($spec['checkIns'] as $checkIn) {
            OpportunityCheckIn::query()->create([
                'case_id' => $case->id,
                'address_id' => $addressIds[$checkIn['address']],
                'matched_at' => $now->copy()->subHours($checkIn['hours']),
                'kit_name' => $checkIn['kit'] ?? null,
            ]);
        }

        if (! empty($spec['dispatch'])) {
            $dispatch = $spec['dispatch'];
            OpportunityDispatch::query()->create([
                'case_id' => $case->id,
                'driver_id' => $drivers[$dispatch['driver']]->id,
                'priority' => $dispatch['priority'],
                'due_window' => $dispatch['due'],
                'note' => $dispatch['note'],
                'status' => $dispatch['status'],
                'stop_order' => $dispatch['stop'],
            ]);
        }
    }
}
