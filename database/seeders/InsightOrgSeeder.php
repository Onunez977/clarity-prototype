<?php

namespace Database\Seeders;

use App\Models\Integrations;
use App\Models\Org;
use Illuminate\Database\Seeder;

class InsightOrgSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $org = Org::query()
            ->where('name', 'InsightLPR')
            ->where('type', 1);

        if ($org->doesntExist()) {
            Org::factory()
                ->afterCreating(function (Org $org) {
                    Integrations::factory()->create([
                        'name' => 'RDN',
                        'mapping_id' => $org->id,
                        'mapping_type' => 'org',
                        'status' => 'active',
                        'batch_id' => 1,
                    ]);

                    Integrations::factory()->create([
                        'name' => 'LS',
                        'mapping_id' => $org->id,
                        'mapping_type' => 'org',
                        'status' => 'active',
                        'batch_id' => 1,
                    ]);
                })
                ->create([
                    'name' => 'InsightLPR',
                    'type' => 1,
                ]);
        }
    }
}
