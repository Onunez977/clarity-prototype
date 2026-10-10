<?php

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\GenerateOrgCache;
use App\Models\Org;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use InsightLPR\BlackAdam\Enum\OrgType;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class GenerateOrgCacheTest extends TestCase
{
    #[Group('commands')]
    public function test_batches_active_org_stat_jobs(): void
    {
        Bus::fake();

        $org = Org::factory()->create([
            'name' => 'Test Org',
            'type' => OrgType::Repo->value,
            'enable_genetech_hotlist' => true,
        ]);

        Artisan::call(GenerateOrgCache::class);

        Bus::assertBatched(function (PendingBatch $batch) use ($org) {
            return $batch->name == 'Org Cache: '.$org->id;
        });
    }

    #[Group('commands')]
    public function test_does_not_batch_for_inactive_org(): void
    {
        Bus::fake();

        Org::factory()->create([
            'name' => 'Test Org',
            'type' => OrgType::Repo->value,
            'enable_genetech_hotlist' => false,
        ]);

        Artisan::call(GenerateOrgCache::class);

        Bus::assertNothingBatched();
    }

    #[Group('commands')]
    public function test_batches_given_org_stat_jobs(): void
    {
        Bus::fake();

        $org = Org::factory()->create([
            'name' => 'Test Org',
            'type' => OrgType::Repo->value,
            'enable_genetech_hotlist' => true,
        ]);

        Artisan::call(GenerateOrgCache::class, [
            'org' => $org->id,
        ]);

        Bus::assertBatched(function (PendingBatch $batch) use ($org) {
            return $batch->name == 'Org Cache: '.$org->id;
        });
    }
}
