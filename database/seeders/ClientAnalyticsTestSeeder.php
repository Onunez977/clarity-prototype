<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Dto\HotlistJsonData;
use App\Enums\HotlistStatusEnum;
use App\Models\AggregateRdnHotlistCountByHour;
use App\Models\Branch;
use App\Models\Hotlist;
use App\Models\HotlistJson;
use App\Models\HotlistMeta;
use App\Models\Integrations;
use App\Models\Kit;
use App\Models\MissedOpportunity;
use App\Models\Org;
use App\Models\Recoveries;
use App\Models\RecoveriesKitUserScansReport;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;
use InsightLPR\BlackAdam\Enum\OrgType;

class ClientAnalyticsTestSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding client analytics test data...');

        $mainOrg = Org::query()
            ->where('name', 'Test Company')
            ->first();

        $this->command->info("Using main org: {$mainOrg->name}");

        $this->command->info('Cleaning up existing test clients...');
        $existingTestClients = Org::where('name', 'like', 'ABC-Test%')->get();
        foreach ($existingTestClients as $client) {
            $hotlistIds = Hotlist::where('client_org_id', $client->id)->pluck('id');
            RecoveriesKitUserScansReport::whereIn('recovery_id', $hotlistIds)->delete();
            HotlistJson::whereIn('hotlist_id', $hotlistIds)->delete();
            HotlistMeta::whereIn('hotlist_id', $hotlistIds)->delete();
            AggregateRdnHotlistCountByHour::where('client_org_id', $client->id)->delete();
            Recoveries::where('client_org_id', $client->id)->delete();
            Hotlist::where('client_org_id', $client->id)->delete();
            $client->delete();
        }

        $this->command->info('Creating test clients...');
        $clients = collect([
            ['name' => 'ABC-Test Client Alpha', 'profile' => 'high'],
            ['name' => 'ABC-Test Client Beta', 'profile' => 'medium'],
            ['name' => 'ABC-Test Client Gamma', 'profile' => 'low'],
            ['name' => 'ABC-Test Client Delta', 'profile' => 'declining'],
            ['name' => 'ABC-Test Client Epsilon', 'profile' => 'new'],
        ])->map(fn ($data) => [
            'org' => Org::factory()->create([
                'name' => $data['name'],
                'type' => OrgType::Client->value,
            ]),
            'profile' => $data['profile'],
        ]);

        $this->command->info('Creating test branches...');
        $branches = [
            Branch::factory()->forOrg($mainOrg)->create(['branch_name' => 'Test Branch North']),
            Branch::factory()->forOrg($mainOrg)->create(['branch_name' => 'Test Branch South']),
        ];

        $testIntegration = Integrations::firstOrCreate(
            [
                'name' => 'Test Dashboard Integration',
                'mapping_id' => $mainOrg->id,
                'mapping_type' => 'org',
            ],
            [
                'status' => 'active',
            ]
        );

        $this->command->info('Generating aggregate hotlist data (25 months)...');
        $this->seedAggregateData($mainOrg, $clients, $branches);

        $this->command->info('Creating hotlist records...');
        $repossessedHotlists = $this->seedHotlists($mainOrg, $clients, $testIntegration, $branches);

        $this->command->info('Creating hotlist_json records with branch_id...');
        $this->seedHotlistJson($repossessedHotlists, $branches);

        $this->command->info('Creating recoveries records...');
        $this->seedRecoveries($repossessedHotlists);

        $this->command->info('Creating hotlist meta...');
        $this->seedHotlistMeta($repossessedHotlists);

        $this->command->info('Creating LPR assist data...');
        $this->seedLprAssists($mainOrg, $repossessedHotlists);

        $this->command->info('Creating missed opportunities...');
        $this->seedMissedOpportunities($mainOrg, $clients);
    }

    /**
     * Seed aggregate hotlist count data for 25 months (2+ years for YoY comparisons).
     * Creates hourly aggregate records for different order types and branches.
     */
    protected function seedAggregateData(Org $mainOrg, $clients, array $branches): void
    {
        $orderTypes = [1, 2, 3, 4, 5];
        $recordsCreated = 0;

        for ($monthsAgo = 25; $monthsAgo >= 0; $monthsAgo--) {
            $monthStart = now()->subMonths($monthsAgo)->startOfMonth();

            $baseDate = $monthsAgo === 0
                ? now()->startOfDay()
                : $monthStart->copy()->addDays(14);

            foreach ($clients as $data) {
                $client = $data['org'];
                $profile = $data['profile'];

                if ($profile === 'new' && $monthsAgo > 3) {
                    continue;
                }

                foreach ($orderTypes as $orderType) {
                    $count = $this->getCountForProfile($profile, $orderType);

                    $branchRand = rand(1, 10);
                    $branchId = match (true) {
                        $branchRand <= 4 => (string) $branches[0]->branch_id,
                        $branchRand <= 8 => (string) $branches[1]->branch_id,
                        default => null,
                    };

                    $hourTimestamp = $baseDate->copy()->addHours(rand(0, 23));

                    AggregateRdnHotlistCountByHour::factory()->create([
                        'org_id' => $mainOrg->id,
                        'client_org_id' => $client->id,
                        'branch_id' => $branchId,
                        'order_type' => $orderType,
                        'hour_timestamp' => $hourTimestamp,
                        'hotlist_count' => $count,
                    ]);

                    $recordsCreated++;
                }
            }
        }

        $this->command->info("  Created $recordsCreated aggregate records");
    }

    /**
     * Get realistic count based on client profile and order type.
     * Order types: 1=Standard, 2=Priority, 3=Express, 4=Declined, 5=New
     */
    protected function getCountForProfile(string $profile, int $orderType): int
    {
        return match ($profile) {
            'high' => match ($orderType) {
                1 => rand(200, 400),
                2 => rand(100, 200),
                3 => rand(40, 80),
                4 => rand(10, 30),
                5 => rand(150, 300),
                default => rand(50, 100),
            },
            'medium' => match ($orderType) {
                1 => rand(100, 200),
                2 => rand(50, 100),
                3 => rand(20, 40),
                4 => rand(5, 15),
                5 => rand(80, 150),
                default => rand(25, 75),
            },
            'low' => match ($orderType) {
                1 => rand(20, 50),
                2 => rand(10, 30),
                3 => rand(3, 10),
                4 => rand(1, 5),
                5 => rand(15, 40),
                default => rand(5, 20),
            },
            'declining' => match ($orderType) {
                1 => rand(10, 30),
                2 => rand(5, 15),
                3 => rand(0, 2),
                4 => rand(80, 150),
                5 => rand(90, 180),
                default => rand(3, 10),
            },
            'new' => match ($orderType) {
                1 => rand(50, 100),
                2 => rand(30, 60),
                3 => rand(10, 20),
                4 => rand(3, 8),
                5 => rand(40, 80),
                default => rand(10, 30),
            },
            default => rand(10, 50),
        };
    }

    /**
     * Seed hotlist records (repossessed and active).
     * Returns collection of repossessed hotlists for further processing.
     */
    protected function seedHotlists(Org $mainOrg, $clients, Integrations $integration, array $branches): Collection
    {
        $repossessedHotlists = collect();

        for ($i = 1; $i <= 800; $i++) {
            if ($i <= 120) {
                $repoDate = now()->subDays(rand(0, now()->day - 1));
            } else {
                $monthsAgo = rand(0, 24);
                $repoDate = now()->subMonths($monthsAgo)->subDays(rand(0, 28));
            }

            if ($i % 5 <= 2) {
                $client = $clients->where('profile', 'high')
                    ->merge($clients->where('profile', 'medium'))
                    ->random()['org'];
            } else {
                $client = $clients->random()['org'];
            }

            $branchRand = rand(1, 10);
            $branchId = match (true) {
                $branchRand <= 4 => $branches[0]->branch_id,
                $branchRand <= 8 => $branches[1]->branch_id,
                default => null,
            };

            $hotlist = Hotlist::factory()
                ->forOrg($mainOrg)
                ->repossessed()
                ->create([
                    'client_org_id' => $client->id,
                    'integration_id' => $integration->id,
                ]);

            $hotlist->temp_repo_date = $repoDate;
            $hotlist->temp_branch_id = $branchId;
            $repossessedHotlists->push($hotlist);
        }

        Hotlist::factory()->count(160)
            ->forOrg($mainOrg)
            ->create([
                'status' => HotlistStatusEnum::Open->value,
                'client_org_id' => $clients->random()['org']->id,
                'integration_id' => $integration->id,
            ]);

        $this->command->info('  Created 960 hotlists (800 repossessed, 160 active)');

        return $repossessedHotlists;
    }

    /**
     * Seed hotlist_json records with branch_id for filtering.
     */
    protected function seedHotlistJson($repossessedHotlists, array $branches): void
    {
        $created = 0;

        foreach ($repossessedHotlists as $hotlist) {
            $branchId = $hotlist->temp_branch_id ?? null;

            if ($branchId !== null) {
                HotlistJson::create([
                    'hotlist_id' => $hotlist->id,
                    'json' => new HotlistJsonData(branch_id: $branchId),
                ]);
                $created++;
            }
        }

        $this->command->info("  Created $created hotlist_json records with branch_id");
    }

    /**
     * Seed recoveries records (populates recoveries table for repossession stats).
     */
    protected function seedRecoveries($repossessedHotlists): void
    {
        foreach ($repossessedHotlists as $hotlist) {
            $repoDate = $hotlist->temp_repo_date ?? now();

            Recoveries::create([
                'integration_id' => IntegrationEnum::RDN->value,
                'remote_order_id' => $hotlist->remote_order_id,
                'status' => $hotlist->status,
                'org_id' => $hotlist->org_id,
                'client_org_id' => $hotlist->client_org_id,
                'lienholder_org_id' => $hotlist->lienholder_org_id,
                'order_type' => $hotlist->order_type,
                'is_staged' => $hotlist->is_staged,
                'plate' => $hotlist->plate,
                'us_state' => $hotlist->us_state,
                'vin' => $hotlist->vin,
                'vehicle_year' => $hotlist->vehicle_year,
                'vehicle_make' => $hotlist->vehicle_make,
                'vehicle_model' => $hotlist->vehicle_model,
                'lookback_days' => $hotlist->lookback_days,
                'repo_date' => $repoDate,
            ]);
        }

        $this->command->info('  Created '.count($repossessedHotlists).' recovery records');
    }

    /**
     * Seed hotlist meta (for days-to-repo calculations).
     */
    protected function seedHotlistMeta($repossessedHotlists): void
    {
        foreach ($repossessedHotlists as $hotlist) {
            $repoDate = $hotlist->temp_repo_date ?? now();

            $daysToRepo = match (rand(1, 3)) {
                1 => rand(5, 15),
                2 => rand(16, 40),
                3 => rand(41, 60),
            };

            HotlistMeta::factory()->create([
                'hotlist_id' => $hotlist->id,
                'order_date' => $repoDate->copy()->subDays($daysToRepo),
                'repo_date' => $repoDate,
            ]);
        }

        $this->command->info('  Created '.count($repossessedHotlists).' hotlist meta records');
    }

    /**
     * Seed LPR assist data (~80% of repos have LPR assists).
     */
    protected function seedLprAssists(Org $mainOrg, $repossessedHotlists): void
    {
        $existingKits = Kit::where('org_id', $mainOrg->id)->get();

        if ($existingKits->isEmpty()) {
            $this->command->warn('  No kits found for main org, skipping LPR assists');

            return;
        }

        $assistedRepos = $repossessedHotlists->random((int) ($repossessedHotlists->count() * 0.8));

        foreach ($assistedRepos as $hotlist) {
            $repoDate = $hotlist->temp_repo_date ?? now();

            RecoveriesKitUserScansReport::factory()->create([
                'recovery_id' => $hotlist->id,
                'org_id' => $mainOrg->id,
                'client_org_id' => $hotlist->client_org_id,
                'remote_order_id' => $hotlist->remote_order_id,
                'repo_date' => $repoDate,
                'kit_id' => $existingKits->random()->id,
                'count' => rand(1, 10),
            ]);
        }

        $this->command->info('  Created '.count($assistedRepos).' LPR assist records');
    }

    /**
     * Seed missed opportunities distributed across test clients.
     */
    protected function seedMissedOpportunities(Org $mainOrg, $clients): void
    {
        $totalCreated = 0;

        foreach ($clients as $data) {
            for ($i = 1; $i <= 2; $i++) {
                MissedOpportunity::factory()
                    ->forOrg($mainOrg)
                    ->create();

                $totalCreated++;
            }
        }

        $this->command->info("  Created $totalCreated missed opportunities (2 per client)");
    }
}
