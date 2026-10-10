<?php

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\GenerateRdnCache;
use App\Jobs\Rdn\GetOrgBranches;
use App\Jobs\Rdn\GetOrgHotlistCases;
use App\Jobs\Rdn\GetOrgRdnClients;
use App\Models\Org;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use InsightLPR\BlackAdam\Enum\OrgType;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class PullRdnOrgClientsTest extends TestCase
{
    #[Group('commands')]
    public function test_dispatches_for_active_org(): void
    {
        Bus::fake();

        Org::factory()->create([
            'name' => 'Test Org',
            'type' => OrgType::Repo->value,
            'enable_genetech_hotlist' => true,
            'rdn_api_key' => fake()->uuid(),
        ]);

        Artisan::call(GenerateRdnCache::class);

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->contains(fn ($job) => $job instanceof GetOrgRdnClients);
        });
    }

    #[Group('commands')]
    public function test_does_not_dispatch_for_inactive_org(): void
    {
        Bus::fake();

        Org::factory()->create([
            'name' => 'Test Org',
            'type' => OrgType::Repo->value,
            'enable_genetech_hotlist' => false,
            'rdn_api_key' => fake()->uuid(),
        ]);

        Artisan::call(GenerateRdnCache::class);

        Bus::assertNothingBatched();
    }

    #[Group('commands')]
    public function test_does_not_dispatch_for_org_without_rdn_api_key(): void
    {
        Bus::fake();

        Org::factory()->create([
            'name' => 'Test Org',
            'type' => OrgType::Repo->value,
            'enable_genetech_hotlist' => false,
            'rdn_api_key' => null,
        ]);

        Artisan::call(GenerateRdnCache::class);

        Bus::assertNothingBatched();
    }

    #[Group('commands')]
    public function test_dispatches_for_given_active_org(): void
    {
        Bus::fake();

        $org = Org::factory()->create([
            'name' => 'Test Org',
            'type' => OrgType::Repo->value,
            'enable_genetech_hotlist' => true,
            'rdn_api_key' => fake()->uuid(),
        ]);

        Artisan::call(GenerateRdnCache::class, [
            'org' => $org->id,
        ]);

        Bus::assertBatched(function ($batch) {
            return $batch->jobs->contains(fn ($job) => $job instanceof GetOrgRdnClients);
        });
    }

    #[Group('commands')]
    public function test_dispatches_hotlist_cases_for_active_org(): void
    {
        Bus::fake();

        Org::factory()->create([
            'name' => 'Test Org',
            'type' => OrgType::Repo->value,
            'enable_genetech_hotlist' => true,
            'rdn_api_key' => fake()->uuid(),
        ]);

        Artisan::call(GenerateRdnCache::class);

        Bus::assertBatched(fn ($batch) => $batch->jobs->contains(fn ($job) => $job instanceof GetOrgHotlistCases));
    }

    /**
     * GetOrgHotlistCases resolves branch names against Branch rows GetOrgBranches writes,
     * so it has to become available after them. Batch::add() forwards to Queue::bulk(),
     * which is the one place a per-job delay could be dropped - so this drives a real
     * driver and reads back the queued availability rather than the in-memory property.
     */
    #[Group('commands')]
    public function test_hotlist_cases_becomes_available_after_the_branch_pull(): void
    {
        $this->freezeTime();

        config(['queue.default' => 'database']);

        $org = Org::factory()->create([
            'name' => 'Test Org',
            'type' => OrgType::Repo->value,
            'enable_genetech_hotlist' => true,
            'rdn_api_key' => fake()->uuid(),
        ]);

        Artisan::call(GenerateRdnCache::class);

        $availableAt = DB::table('jobs')->get()->mapWithKeys(fn (object $row): array => [
            json_decode($row->payload)->displayName => (int) $row->available_at,
        ]);

        $this->assertSame(now()->getTimestamp(), $availableAt[GetOrgBranches::class]);
        $this->assertSame(
            now()->addSeconds(GetOrgHotlistCases::dispatchDelaySeconds($org->id))->getTimestamp(),
            $availableAt[GetOrgHotlistCases::class],
        );
        $this->assertGreaterThan($availableAt[GetOrgBranches::class], $availableAt[GetOrgHotlistCases::class]);
    }

    /**
     * ~3,493 org dispatchers used to become available on the same second. The phase is a
     * hash of the org id, so it is stable across runs (a run is reproducible) and differs
     * between orgs (the herd is broken, not merely moved).
     */
    #[Group('commands')]
    public function test_the_dispatcher_delay_is_stable_per_org_and_differs_between_orgs(): void
    {
        $this->assertSame(
            GetOrgHotlistCases::dispatchDelaySeconds(4242),
            GetOrgHotlistCases::dispatchDelaySeconds(4242),
        );

        $delays = array_map(
            fn (int $orgId): int => GetOrgHotlistCases::dispatchDelaySeconds($orgId),
            range(1, 20),
        );

        $this->assertGreaterThan(1, count(array_unique($delays)));
        $this->assertGreaterThanOrEqual(
            GetOrgHotlistCases::BRANCH_SYNC_HEAD_START_MINUTES * 60,
            min($delays),
        );
        $this->assertLessThan(
            (GetOrgHotlistCases::BRANCH_SYNC_HEAD_START_MINUTES + GetOrgHotlistCases::DISPATCH_SPREAD_MINUTES) * 60,
            max($delays),
        );
    }
}
